<?php

namespace craft\feedme\fields;

use Cake\Utility\Hash;
use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\feedme\base\Field;
use craft\feedme\base\FieldInterface;
use craft\feedme\Plugin;
use craft\feedme\services\Process;
use craft\fields\Matrix as MatrixField;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use craft\services\Elements;
use Throwable;
use WeakMap;
use yii\base\Event;

/**
 * Imports HTML into CKEditor fields, along with any nested entries it references.
 *
 * Nested entries are mapped like Matrix entries, by entry type. In the HTML, they're referenced by
 * `<craft-entry data-entry-id="…">` placeholders, matched to the mapped entries in order. Nested entries can only
 * be created once the element that owns the field has been saved, so the placeholders are set aside until then:
 * the element is saved with the rest of the HTML, then the nested entries are created and the HTML is saved again
 * with their IDs.
 *
 * @property-read string $mappingTemplate
 */
class CKEditor extends Field implements FieldInterface
{
    // Properties
    // =========================================================================

    /**
     * @var string
     */
    public static string $name = 'CKEditor';

    /**
     * @var string
     */
    public static string $class = 'craft\ckeditor\Field';

    /**
     * @var WeakMap|null Field values whose nested entries are created once their owner is saved
     */
    private static ?WeakMap $_pendingValues = null;

    /**
     * @var array Owners saved with pending field values, whose nested entries are created after the feed's element is saved
     */
    private static array $_pendingOwners = [];

    /**
     * @var bool
     */
    private static bool $_registeredEvents = false;

    // Templates
    // =========================================================================

    /**
     * @inheritDoc
     */
    public function getMappingTemplate(): string
    {
        return 'feed-me/_includes/fields/ckeditor';
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public function init(): void
    {
        parent::init();

        if (self::$_registeredEvents) {
            return;
        }

        self::$_registeredEvents = true;
        self::$_pendingValues = new WeakMap();

        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event) {
            $this->_findPendingOwner($event->element);
        });

        Event::on(Process::class, Process::EVENT_STEP_AFTER_ELEMENT_SAVE, function() {
            $this->_createPendingEntries();
        });
    }

    /**
     * @inheritDoc
     */
    public function parseField(): mixed
    {
        // Nested entries can have CKEditor fields of their own, which reuse this instance while they're parsed
        $field = $this->field;
        $siteId = $this->element->siteId ?? null;

        $node = Hash::get($this->fieldInfo, 'node');
        $html = $this->fetchValue();

        if (is_array($html)) {
            $html = Json::encode($html);
        }

        $entries = Hash::get($this->fieldInfo, 'blocks') ? $this->_parseEntries($node) : [];

        if (!$entries) {
            return $html;
        }

        // Split the HTML around its nested entry placeholders, which are matched to the entries in order
        $segments = preg_split('/<craft-entry\b[^>]*>.*?<\/craft-entry>/is', (string)$html);

        // Each placeholder stands in until its entry exists. An ID of 0 never matches an entry, but keeps
        // the field from looking empty, so it still passes validation if it's required.
        $value = $this->_createFieldData($field, implode('<craft-entry data-entry-id="0">&nbsp;</craft-entry>', $segments), $siteId);

        self::$_pendingValues[$value] = [
            'segments' => $segments,
            'entries' => $entries,
        ];

        return $value;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the mapped data for each nested entry, by its position among the field's entries.
     * Entries without any mapped data are `null`, so they still line up with their placeholders.
     *
     * @param string|null $htmlNode
     * @return array
     */
    private function _parseEntries(?string $htmlNode): array
    {
        $entryFeedData = [];
        $entryNodes = [];

        foreach (Hash::flatten(Hash::get($this->fieldInfo, 'blocks', [])) as $path => $value) {
            if (str_ends_with($path, '.node')) {
                $entryNodes[$value] = true;
            }
        }

        foreach ($this->feedData as $nodePath => $value) {
            // Only the nested entries' data, which is indexed by its position in the field (the first index in the node path)
            if (
                $nodePath === $htmlNode ||
                !isset($entryNodes[preg_replace('/\/\d+(?=\/|$)/', '', $nodePath)]) ||
                !preg_match('/^.+?\/(\d+)(\/|$)/', $nodePath, $matches)
            ) {
                continue;
            }

            $index = (int)$matches[1];
            $entryFeedData[$index][preg_replace('/^(.+?\/)\d+(\/|$)/', '${1}0$2', $nodePath, 1)] = $value;
        }

        if (!$entryFeedData) {
            return [];
        }

        ksort($entryFeedData);

        $matrix = clone Plugin::$plugin->fields->getRegisteredField(MatrixField::class);
        $matrix->fieldHandle = $this->fieldHandle;
        $matrix->fieldInfo = ['field' => MatrixField::class, 'blocks' => Hash::get($this->fieldInfo, 'blocks')];
        $matrix->field = $this->field;
        $matrix->element = $this->element;
        $matrix->feed = $this->feed;

        $entries = array_fill(0, max(array_keys($entryFeedData)) + 1, null);

        foreach ($entryFeedData as $index => $feedData) {
            $matrix->feedData = $feedData;
            $parsed = $matrix->parseField();
            $entries[$index] = $parsed ? reset($parsed) : null;
        }

        return $entries;
    }

    /**
     * Creates the field's value object, so pending entries can be tracked through the save.
     *
     * @param mixed $field
     * @param string $html
     * @param int|null $siteId
     * @return mixed
     */
    private function _createFieldData(mixed $field, string $html, ?int $siteId): mixed
    {
        // createFieldData() is protected, and normalizeValue() would return null for HTML that's only placeholders
        return (fn(string $html, ?int $siteId) => $this->createFieldData($html, $siteId))->call($field, $html, $siteId);
    }

    /**
     * Records an element that was saved with a pending field value, now that it has an ID to own the nested entries.
     *
     * @param ElementInterface $element
     */
    private function _findPendingOwner(ElementInterface $element): void
    {
        if (!self::$_pendingValues || !count(self::$_pendingValues)) {
            return;
        }

        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if (!is_a($field, static::$class)) {
                continue;
            }

            $value = $element->getFieldValue($field->handle);

            if (!is_object($value) || !isset(self::$_pendingValues[$value])) {
                continue;
            }

            self::$_pendingOwners[] = [
                'elementId' => $element->id,
                'elementType' => get_class($element),
                'siteId' => $element->siteId,
                'fieldHandle' => $field->handle,
                'field' => $field,
            ] + self::$_pendingValues[$value];

            unset(self::$_pendingValues[$value]);
        }
    }

    /**
     * Creates the nested entries for each owner saved by this step, then saves the owner's HTML with their IDs.
     */
    private function _createPendingEntries(): void
    {
        $pendingOwners = self::$_pendingOwners;
        self::$_pendingOwners = [];

        foreach ($pendingOwners as $pending) {
            try {
                $this->_createEntriesForOwner($pending);
            } catch (Throwable $e) {
                Plugin::error('Unable to create nested entries for `{handle}`: `{e} - {f}: {l}`.', [
                    'handle' => $pending['fieldHandle'],
                    'e' => $e->getMessage(),
                    'f' => basename($e->getFile()),
                    'l' => $e->getLine(),
                ]);
                Craft::$app->getErrorHandler()->logException($e);
            }
        }
    }

    /**
     * @param array $pending
     * @throws Throwable
     */
    private function _createEntriesForOwner(array $pending): void
    {
        $elementsService = Craft::$app->getElements();
        $field = $pending['field'];

        $owner = $elementsService->getElementById($pending['elementId'], $pending['elementType'], $pending['siteId']);

        if (!$owner) {
            return;
        }

        $html = '';
        $entryIds = [];

        foreach ($pending['segments'] as $i => $segment) {
            $html .= $segment;

            // There's a placeholder after every segment but the last
            if ($i === count($pending['segments']) - 1) {
                break;
            }

            $entryData = $pending['entries'][$i] ?? null;
            $entry = $entryData ? $this->_createEntry($entryData, $field, $owner) : null;

            if ($entry) {
                $entryIds[] = $entry->id;
                $html .= '<craft-entry data-entry-id="' . $entry->id . '">&nbsp;</craft-entry>';
            }
        }

        $owner->setFieldValue($pending['fieldHandle'], $html);

        if (!$elementsService->saveElement($owner)) {
            Plugin::error('Unable to save nested entries for `{handle}` on element `#{id}`: `{e}`.', [
                'handle' => $pending['fieldHandle'],
                'id' => $owner->id,
                'e' => Json::encode($owner->getErrors()),
            ]);

            return;
        }

        // Remove nested entries left over from a previous import, which the HTML no longer references
        $staleEntries = Entry::find()
            ->fieldId($field->id)
            ->ownerId($owner->id)
            ->primaryOwnerId($owner->id)
            ->siteId($owner->siteId)
            ->id($entryIds ? array_merge(['not'], $entryIds) : null)
            ->status(null)
            ->all();

        foreach ($staleEntries as $staleEntry) {
            $elementsService->deleteElement($staleEntry);
        }

        Plugin::info('Created {count} nested entries for `{handle}` on element `#{id}`.', [
            'count' => count($entryIds),
            'handle' => $pending['fieldHandle'],
            'id' => $owner->id,
        ]);
    }

    /**
     * @param array $entryData
     * @param mixed $field
     * @param ElementInterface $owner
     * @return Entry|null
     * @throws Throwable
     */
    private function _createEntry(array $entryData, mixed $field, ElementInterface $owner): ?Entry
    {
        $entryType = ArrayHelper::firstWhere($field->getEntryTypes(), 'handle', $entryData['type'] ?? null);

        if (!$entryType) {
            return null;
        }

        $entry = new Entry();
        $entry->siteId = $owner->siteId;
        $entry->typeId = $entryType->id;
        $entry->fieldId = $field->id;
        $entry->setOwner($owner);
        $entry->setPrimaryOwner($owner);
        $entry->enabled = (bool)($entryData['enabled'] ?? true);

        foreach (['title', 'slug'] as $attribute) {
            if (!empty($entryData[$attribute])) {
                $entry->$attribute = $entryData[$attribute];
            }
        }

        $entry->setFieldValues($entryData['fields'] ?? []);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            Plugin::error('Unable to save a nested `{type}` entry for `{handle}`: `{e}`.', [
                'type' => $entryType->handle,
                'handle' => $field->handle,
                'e' => Json::encode($entry->getErrors()),
            ]);

            return null;
        }

        return $entry;
    }
}
