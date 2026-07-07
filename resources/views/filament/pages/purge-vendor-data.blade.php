<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            Purge Vendor Catalog Data
        </x-slot>
        <x-slot name="description">
            Use this once VIT has confirmed a vendor's catalog has been successfully loaded into eLink.
            This clears the vendor's catalog items from the portal so their onboarding workspace can start
            fresh. Optionally also remove the submission records and stored Excel files, or leave them
            archived for audit purposes.
        </x-slot>

        <form wire:submit="purge">
            {{ $this->form }}

            <div class="mt-6">
                <x-filament::button type="submit" color="danger">
                    Purge Selected Vendor's Data
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
