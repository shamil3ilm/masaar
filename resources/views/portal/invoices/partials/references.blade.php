{{-- What the buyer's own paperwork calls the sale, each optional: its
     purchase order, the contract it falls under and its own reference. --}}
@foreach (['order_reference' => __('Purchase order'), 'contract_reference' => __('Contract'), 'buyer_reference' => __('Buyer reference')] as $field => $label)
    <div>
        <x-ui.field :label="$label">
            <x-ui.input type="text" name="{{ $field }}" id="{{ $field }}" maxlength="127" value="{{ old($field) }}" />
        </x-ui.field>
    </div>
@endforeach
