@props(['wireModel', 'options' => [], 'allowCreate' => false, 'placeholder' => 'Select an option...'])

<div {{ $attributes->merge(['class' => 'w-full']) }} wire:ignore x-data="searchableSelect(@js($options), @entangle($wireModel), @js($allowCreate), @js($placeholder))" x-init="init()">
    <select x-ref="select" ></select>
</div>

<script>
    function searchableSelect(options, model, allowCreate, placeholder) {
        return {
            value: model,
            tomSelectInstance: null,
            placeholder: placeholder,

            init() {
                // Prevent double-init on the same element
                if (this.$refs.select.tomselect) {
                    this.tomSelectInstance = this.$refs.select.tomselect;
                    return;
                }

                this.tomSelectInstance = new TomSelect(this.$refs.select, {
                    options: options.map(o => ({
                        value: o.value,
                        text: o.label
                    })),
                    valueField: 'value',
                    labelField: 'text',
                    searchField: 'text',

                    create: allowCreate,
                    createOnBlur: true,
                    placeholder: placeholder,
                    items: this.value ? [this.value] : [],

                    onChange: (val) => {
                        this.value = val;
                    },
                    onOptionAdd: (value, data) => {
                        // dispatch to Livewire so you can persist the new option
                        this.$dispatch('option-created', {
                            value,
                            label: value
                        });
                    },
                });
            }
        }
    }
</script>
