<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Validator;
use App\Models\CustomField;

/**
 * Fields the operator adds without waiting for a code change.
 *
 * Two guard rails are worth stating, because both are the difference between a useful
 * escape hatch and a mess nobody can query:
 *
 *   THE KEY IS IMMUTABLE. It is derived from the label once, on creation, and never
 *   changes - stored answers point at the definition, and renaming the label is the
 *   supported way to change wording. A mutable key would silently orphan every answer.
 *
 *   RETIRING IS NOT DELETING. Setting a field inactive stops it appearing on forms and
 *   keeps every answer readable. Deleting destroys the answers, which is only ever
 *   right when the field was a mistake - so the confirmation says how many answers it
 *   is about to take with it.
 */
final class CustomFieldController extends Controller
{
    public function index(Request $request): void
    {
        $this->guard($request, 'custom_fields.manage', allowAgent: true);

        $this->view($request, 'custom-fields/index', [
            'title'      => 'Custom fields',
            'fields'     => CustomField::all(),
            'entities'   => CustomField::ENTITIES,
            'types'      => CustomField::TYPES,
        ]);
    }

    public function create(Request $request): void
    {
        $this->guard($request, 'custom_fields.manage', allowAgent: true);

        if (!$request->isPost()) {
            $this->view($request, 'custom-fields/form', [
                'title'    => 'Add a custom field',
                'field'    => null,
                'entities' => CustomField::ENTITIES,
                'types'    => CustomField::TYPES,
            ]);
        }

        $validator = $this->validate($request);
        if ($validator->fails()) {
            $this->backWithErrors('/custom-fields/create', $validator->errors(), $request->all());
        }

        $entity = $request->str('entity');
        $label = $request->str('label');

        $data = $this->payload($request) + [
            'entity'     => $entity,
            // Derived once and then frozen. See the class note.
            'field_key'  => CustomField::uniqueKey($entity, $label),
            'created_by' => Auth::id(),
        ];

        $id = CustomField::create($data);

        Logger::audit('create', 'custom_field', $id, null, $data, sprintf(
            'Added custom field "%s" on %s',
            $data['label'],
            CustomField::ENTITIES[$entity] ?? $entity
        ));

        $this->back('/custom-fields', 'success', sprintf('Field "%s" added.', e($data['label'])));
    }

    public function edit(Request $request): void
    {
        $this->guard($request, 'custom_fields.manage', allowAgent: true);

        $id = $request->paramInt('id');
        $field = CustomField::find($id);

        if ($field === null) {
            $this->back('/custom-fields', 'danger', 'That field could not be found.');
        }

        if (!$request->isPost()) {
            $this->view($request, 'custom-fields/form', [
                'title'    => 'Edit custom field',
                'field'    => $field,
                'entities' => CustomField::ENTITIES,
                'types'    => CustomField::TYPES,
            ]);
        }

        $validator = $this->validate($request, true);
        if ($validator->fails()) {
            $this->backWithErrors('/custom-fields/' . $id . '/edit', $validator->errors(), $request->all());
        }

        // Neither the entity nor the key is editable. Moving a field to another entity
        // would leave its answers attached to records of the wrong type, and changing
        // the key would orphan them outright.
        $data = $this->payload($request);

        CustomField::update($id, $data);

        Logger::auditDiff('custom_field', $id, $field, $data, sprintf(
            'Updated custom field "%s"',
            $data['label']
        ));

        $this->back('/custom-fields', 'success', sprintf('Field "%s" updated.', e($data['label'])));
    }

    public function delete(Request $request): void
    {
        $this->guard($request, 'custom_fields.manage', allowAgent: true);

        $id = $request->paramInt('id');
        $field = CustomField::find($id);

        if ($field === null) {
            $this->back('/custom-fields', 'danger', 'That field could not be found.');
        }

        $answers = CustomField::answerCount($id);

        CustomField::delete($id);

        Logger::audit('delete', 'custom_field', $id, $field, null, sprintf(
            'Deleted custom field "%s" and %d recorded answer(s)',
            (string) $field['label'],
            $answers
        ));

        $this->back('/custom-fields', 'success', sprintf(
            'Field "%s" deleted%s.',
            e((string) $field['label']),
            $answers === 0 ? '' : sprintf(' along with %d recorded answer(s)', $answers)
        ));
    }

    // -----------------------------------------------------------------------

    private function validate(Request $request, bool $isEdit = false): Validator
    {
        $rules = [
            'label'      => 'required|max:120',
            'field_type' => 'required|in:' . implode(',', array_keys(CustomField::TYPES)),
            'hint'       => 'nullable|max:255',
            'sort_order' => 'nullable|integer',
            'status'     => 'required|in:active,inactive',
        ];

        if (!$isEdit) {
            $rules['entity'] = 'required|in:' . implode(',', array_keys(CustomField::ENTITIES));
        }

        return Validator::make($request->all(), $rules, [
            'field_type' => 'Field type',
            'sort_order' => 'Display order',
        ]);
    }

    /** @return array<string,mixed> */
    private function payload(Request $request): array
    {
        $type = $request->str('field_type');
        $options = $request->nullableStr('options');

        // Options are meaningless for every other type, and leaving a stale list behind
        // after somebody switches a select to a text box is how a field ends up with
        // rules nothing enforces.
        if ($type !== 'select') {
            $options = null;
        }

        return [
            'label'          => $request->str('label'),
            'field_type'     => $type,
            'options'        => $options,
            'hint'           => $request->nullableStr('hint'),
            'is_required'    => $request->bool('is_required') ? 1 : 0,
            'show_in_report' => $request->bool('show_in_report') ? 1 : 0,
            'sort_order'     => $request->int('sort_order'),
            'status'         => $request->str('status') === 'inactive' ? 'inactive' : 'active',
        ];
    }
}
