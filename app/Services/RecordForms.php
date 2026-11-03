<?php

namespace App\Services;

class RecordForms
{
    public function definitions(string $backend): array
    {
        $product = ['key' => 'product_id', 'label' => 'Choose the product', 'resource' => 'products', 'rules' => 'required|integer'];
        $reason = ['key' => 'reason', 'label' => 'Reason for this stock change', 'rules' => 'required|string|min:3|max:255'];
        $quantity = ['key' => 'quantity', 'label' => 'Quantity in the product’s BASE unit (not packs)', 'rules' => 'required|numeric|gt:0|max:1000000'];
        $method = ['key' => 'method', 'label' => 'How was it paid? This records payment; it does not transfer funds.', 'rules' => 'required|in:CASH,MOBILE_MONEY,CARD,BANK,OTHER',
            'choices' => ['CASH' => 'Cash', 'MOBILE_MONEY' => 'Mobile money', 'CARD' => 'Card', 'BANK' => 'Bank', 'OTHER' => 'Other']];
        $forms = [
            'supplier' => ['title' => 'Add supplier', 'command' => 'supplier.create', 'fields' => [
                ['key' => 'name', 'label' => 'Supplier name', 'rules' => 'required|string|min:2|max:150'],
                ['key' => 'phone', 'label' => 'Phone number', 'rules' => 'nullable|string|max:30', 'optional' => true],
                ['key' => 'email', 'label' => 'Email address', 'rules' => 'nullable|email|max:255', 'optional' => true],
                ['key' => 'address', 'label' => 'Address', 'rules' => 'nullable|string|max:255', 'optional' => true],
            ]],
            'supplier_payment' => ['title' => 'Pay supplier', 'command' => 'supplier.pay', 'target' => 'supplier_id', 'fields' => [
                ['key' => 'supplier_id', 'label' => 'Choose the supplier you paid', 'resource' => 'suppliers', 'rules' => 'required|integer'],
                ['key' => 'amount', 'label' => 'Amount paid to supplier', 'rules' => 'required|integer|min:1|max:1000000000000'], $method,
                ['key' => 'reference', 'label' => 'Payment reference', 'rules' => 'nullable|string|max:100', 'optional' => true],
            ]],
            'damage' => ['title' => 'Damaged stock', 'command' => 'stock.damage', 'fields' => [$product, $quantity, $reason]],
            'loss' => ['title' => 'Lost stock', 'command' => 'stock.loss', 'fields' => [$product, $quantity, $reason]],
            'count' => ['title' => 'Stock count', 'command' => 'stock.count', 'fields' => [$product,
                ['key' => 'counted_quantity', 'label' => 'Actual stock counted in BASE units. This sets a count; it does not add this number.', 'rules' => 'required|numeric|min:0|max:1000000'], $reason]],
            'opening' => ['title' => 'Opening stock', 'command' => 'stock.opening', 'fields' => [$product, $quantity,
                ['key' => 'unit_cost', 'label' => 'Cost per BASE unit', 'rules' => 'required|integer|min:0|max:1000000000000']]],
            'expense' => ['title' => 'Record expense', 'command' => 'expense.create', 'fields' => [
                ['key' => 'category', 'label' => 'Expense category, e.g. Rent or Transport', 'rules' => 'required|string|max:100'],
                ['key' => 'amount', 'label' => 'Expense amount', 'rules' => 'required|integer|min:1|max:1000000000000'],
                ['key' => 'expense_date', 'label' => 'Expense date (YYYY-MM-DD)', 'rules' => 'required|date_format:Y-m-d'],
                ['key' => 'description', 'label' => 'Description', 'rules' => 'nullable|string|max:500', 'optional' => true],
            ]],
            'shift_open' => ['title' => 'Open shift', 'command' => 'shift.open', 'cashier' => true, 'fields' => [
                ['key' => 'opening_cash', 'label' => 'Cash physically in the till at opening', 'rules' => 'required|integer|min:0|max:1000000000000'],
            ]],
            'shift_close' => ['title' => 'Close my shift', 'command' => 'shift.close', 'cashier' => true, 'current_shift' => true, 'fields' => [
                ['key' => 'actual_cash', 'label' => 'Cash you physically counted in the till. Count it before entering.', 'rules' => 'required|integer|min:0|max:1000000000000'],
                ['key' => 'note', 'label' => 'Closing note', 'rules' => 'nullable|string|max:255', 'optional' => true],
            ]],
        ];
        $fields = [
            ['key' => 'name', 'label' => 'Product name — include its size/variant', 'rules' => 'required|string|max:255'],
            ['key' => 'base_unit', 'label' => 'BASE unit, e.g. bottle, piece, kg or L. Quantity is measured in this unit.', 'rules' => 'required|string|max:50'],
            ['key' => 'selling_price', 'label' => 'Selling price per BASE unit (0 for inputs that are not sold)', 'rules' => 'required|integer|min:0|max:1000000000000'],
            ['key' => 'current_cost', 'label' => 'Cost per BASE unit', 'rules' => 'required|integer|min:0|max:1000000000000'],
            ['key' => 'sku', 'label' => 'Unique SKU', 'rules' => 'nullable|string|max:100', 'optional' => true],
        ];
        if ($backend === 'production') {
            array_unshift($fields, ['key' => 'kind', 'label' => 'Product type', 'rules' => 'required|in:raw_material,packaging,finished_good', 'choices' => [
                'raw_material' => 'Raw material', 'packaging' => 'Packaging', 'finished_good' => 'Finished / resale good',
            ]]);
        }
        $forms['product'] = ['title' => 'Add product', 'command' => 'product.create', 'fields' => $fields];
        $forms['purchase'] = ['title' => 'Receive stock', 'command' => 'purchase.receive', 'fields' => [
            ['key' => 'supplier_id', 'label' => 'Choose the supplier', 'resource' => 'suppliers', 'rules' => 'required|integer'],
            ['key' => 'items', 'label' => 'Stock received — enter every item', 'fields' => [
                $product,
                ['key' => 'unit_name', 'label' => 'Unit received', 'units_of' => 'product_id', 'rules' => 'required|string|max:50'],
                ['key' => 'quantity', 'label' => 'Quantity in the SELECTED unit', 'rules' => 'required|numeric|gt:0|max:1000000'],
                ['key' => 'unit_cost', 'label' => 'Cost per SELECTED unit, e.g. per box when receiving boxes', 'rules' => 'required|integer|min:0|max:1000000000000'],
            ]],
            ['key' => 'amount_paid', 'label' => 'Amount paid now (0 means entirely on credit)', 'rules' => 'required|integer|min:0|max:1000000000000'],
            ['key' => 'payment_method', 'label' => $method['label'], 'rules' => $method['rules'], 'choices' => $method['choices']],
            ['key' => 'purchase_date', 'label' => 'Purchase date (YYYY-MM-DD); skip for today', 'rules' => 'nullable|date_format:Y-m-d', 'optional' => true],
            ['key' => 'reference', 'label' => 'Supplier receipt / invoice reference', 'rules' => 'nullable|string|max:100', 'optional' => true],
        ]];
        if ($backend === 'production') {
            $outputs = ['key' => 'outputs', 'label' => 'Finished outputs — quantities are in BASE units; equivalent is per BASE unit in the batch yield unit', 'fields' => [
                $product,
                ['key' => 'quantity', 'label' => 'Finished quantity in the product BASE unit', 'rules' => 'required|numeric|gt:0|max:1000000'],
                ['key' => 'output_equivalent', 'label' => 'Equivalent per BASE unit in the batch yield unit (e.g. 0.3 L per 300ml bottle). Skip only if configured on the product.', 'rules' => 'nullable|numeric|gt:0|max:1000000', 'optional' => true],
                ['key' => 'expiry_date', 'label' => 'Output expiry date (YYYY-MM-DD)', 'rules' => 'nullable|date_format:Y-m-d', 'optional' => true],
            ]];
            $forms['recipe'] = ['title' => 'Create recipe', 'command' => 'recipe.create', 'fields' => [
                ['key' => 'name', 'label' => 'Recipe name', 'rules' => 'required|string|max:255'],
                ['key' => 'family', 'label' => 'Recipe family, e.g. Mango Juice', 'rules' => 'nullable|string|max:255', 'optional' => true],
                ['key' => 'yield_quantity', 'label' => 'Recipe yield quantity', 'rules' => 'required|numeric|gt:0|max:1000000'],
                ['key' => 'yield_unit', 'label' => 'Recipe yield unit, e.g. L or kg', 'rules' => 'required|string|max:50'],
                ['key' => 'items', 'label' => 'Recipe ingredients and packaging — quantities are in each product BASE unit', 'fields' => [$product, $quantity]],
                ['key' => 'instructions', 'label' => 'Recipe instructions', 'rules' => 'nullable|string|max:5000', 'optional' => true],
            ]];
            $forms['batch_plan'] = ['title' => 'Plan production batch', 'command' => 'batch.plan', 'fields' => [
                ['key' => 'recipe_id', 'label' => 'Choose recipe. Its ingredients will be scaled by the POS.', 'resource' => 'recipes', 'rules' => 'required|integer'],
                ['key' => 'planned_yield', 'label' => 'Planned yield in the recipe yield unit. Skip to use the recipe’s default yield.', 'rules' => 'nullable|numeric|gt:0|max:1000000', 'optional' => true],
                $outputs,
                ['key' => 'production_date', 'label' => 'Production date (YYYY-MM-DD); skip for today', 'rules' => 'nullable|date_format:Y-m-d', 'optional' => true],
                ['key' => 'notes', 'label' => 'Planning notes. A draft does not move stock.', 'rules' => 'nullable|string|max:2000', 'optional' => true],
            ]];
            $forms['batch_complete'] = ['title' => 'Complete batch', 'command' => 'batch.complete', 'target' => 'batch_id', 'guard_revision' => true, 'fields' => [
                ['key' => 'batch_id', 'label' => 'Choose the DRAFT batch to complete', 'resource' => 'batches', 'query' => ['status' => 'draft'], 'rules' => 'required|integer'],
                ['key' => 'inputs', 'label' => 'Actual inputs used, including packaging. This REPLACES planned inputs; enter every input.', 'fields' => [$product,
                    ['key' => 'actual_quantity', 'label' => 'Actual quantity consumed in this product BASE unit', 'rules' => 'required|numeric|min:0|max:1000000']]],
                $outputs,
                ['key' => 'direct_expenses', 'label' => 'NEW direct costs for this batch. Do not re-enter costs already attached to the batch.', 'optional' => true, 'fields' => [
                    ['key' => 'type', 'label' => 'Direct expense type', 'rules' => 'required|in:direct_labour,direct_production', 'choices' => ['direct_labour' => 'Direct labour', 'direct_production' => 'Direct production']],
                    ['key' => 'category', 'label' => 'Cost category', 'rules' => 'required|string|max:100'],
                    ['key' => 'amount', 'label' => 'Cost amount', 'rules' => 'required|integer|min:1|max:1000000000000'],
                ]],
                ['key' => 'wastage', 'label' => 'Additional stock wasted (not amounts already counted as consumed input)', 'optional' => true, 'fields' => [$product, $quantity, $reason]],
                ['key' => 'notes', 'label' => 'Completion notes. Confirming consumes inputs and creates finished stock.', 'rules' => 'nullable|string|max:2000', 'optional' => true],
            ]];
            $forms['batch_cancel'] = ['title' => 'Cancel batch', 'command' => 'batch.cancel', 'target' => 'batch_id', 'guard_revision' => true, 'fields' => [
                ['key' => 'batch_id', 'label' => 'Choose the batch. Completed output can only be reversed if the POS permits it.', 'resource' => 'batches', 'rules' => 'required|integer'], $reason,
            ]];
        }

        return $forms;
    }
}
