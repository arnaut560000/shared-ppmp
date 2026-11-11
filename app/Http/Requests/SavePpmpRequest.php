<?php

namespace App\Http\Requests;

use App\Services\PlanningAccess;
use Illuminate\Foundation\Http\FormRequest;

class SavePpmpRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ppa = $this->route('ppa');
        PlanningAccess::roles($this->user(), ['admin', 'office']);
        PlanningAccess::view($this->user(), $ppa);
        abort_unless($ppa->type === 'aip' && $ppa->parent_ppa_id !== null, 404);
        if ($sheet = $this->route('ppmp')) {
            abort_unless($sheet->type === 'ppmp' && $sheet->parent_id === $ppa->id
                && $sheet->office_id === $ppa->office_id && $sheet->fiscal_year === $ppa->fiscal_year, 404);
            PlanningAccess::edit($this->user(), $sheet);
            abort_if($sheet->items()->get()->contains(fn ($item) => count($item->monthly_quantities ?? []) !== 12), 409, 'This quarterly list is read-only.');
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:100'],
            'version' => [$this->route('ppmp') ? 'required' : 'nullable', 'integer', 'min:0'],
            'items' => $this->route('ppmp')
                ? ['present', 'array', 'list']
                : ['required', 'array', 'list', 'min:1'],
            'items.*.catalogue_item_id' => ['required', 'integer', 'distinct', 'exists:catalogue_items,id'],
            'items.*.expected_price_cents' => ['required', 'integer', 'min:0'],
            'items.*.monthly_quantities' => ['required', 'array', 'list', 'size:12'],
            'items.*.monthly_quantities.*' => ['required', 'integer', 'between:0,1000000'],
        ];
    }
}
