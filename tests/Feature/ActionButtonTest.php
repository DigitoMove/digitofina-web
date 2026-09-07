<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;

it('keeps form actions submittable and modal controls non submitting', function () {
    foreach (File::allFiles(resource_path('views')) as $file) {
        preg_match_all('/<x-icon-action\b[^>]*\/>/s', $file->getContents(), $matches);

        foreach ($matches[0] as $component) {
            if (str_contains($component, 'href=')) {
                continue;
            }

            $isModalTrigger = str_contains($component, 'data-ledger-modal-open=');
            expect($component, $file->getRelativePathname())
                ->toContain($isModalTrigger ? 'type="button"' : 'type="submit"');
        }
    }
});

it('renders short accessible action labels in content sized buttons', function () {
    $html = Blade::render('<x-icon-action type="submit" text="Delete" label="Delete ledger posting" icon="delete" tone="danger" />');

    expect($html)->toContain('type="submit"', 'aria-label="Delete ledger posting"', '>Delete</span>', 'whitespace-nowrap', 'px-3')
        ->not->toContain('size-9');
});
