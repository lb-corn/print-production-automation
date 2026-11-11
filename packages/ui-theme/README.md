# Print Production UI

One internal theme package for the entire application. Every authenticated page uses `<x-layouts.app>`, which renders this package's layout and the same compact page header. Admin, production, apparel, batches and profile share its dimensions, spacing and account controls. Page content can differ without replacing the shell.

## Source of truth

- `resources/css/app.css`: shared design tokens, layout, controls and production components.
- `resources/css/apparel.css`: apparel-specific tables/cards, within the same theme.
- `resources/views/partials/`: page header, icons, account menu, company selector, messages, shared panels/charts and branding.
- `resources/views/components/`: reusable Blade components.

The public stylesheets are generated compatibility assets. Edit the package sources, then run:

```sh
php packages/ui-theme/build.php
php packages/ui-theme/build.php --check
```

CI checks that published assets match their sources. Existing `partials.*` includes forward to the package so existing screens keep their markup and behavior. `production-ui` is the preferred view/component namespace; the original `lucky-theme` namespace remains compatible.

## Reusing elements

```blade
<x-layouts.app heading="Print Batches" subheading="Factory handoffs">
    <x-production-ui::panel class="my-module">
        <x-production-ui::badge>Vibe</x-production-ui::badge>
        <x-production-ui::button type="submit" variant="primary">Save</x-production-ui::button>
    </x-production-ui::panel>
</x-layouts.app>
```

Buttons support `primary`, `chip`, `approve` and `reject`. Attributes, accessibility labels and disabled states pass through. Use `production-ui::partials.company-field` within an existing filter form, or `production-ui::partials.partner-filter` for a standalone company filter. Supply `companyAutoSubmit=false` when the parent form has its own Apply action. Controllers continue to enforce feature and company permissions; presentation components never grant access.

For list pages, use `x-production-ui::panel-header` (title, description and optional `actions` slot), `x-production-ui::toolbar` for related filters, and `x-production-ui::empty-state` (title, description, icon and optional action slot). Place the title first and the filter toolbar below it. Do not put a bordered company panel inside another panel. Keep sidebar colors scoped to `.sidebar` so in-page tabs and filters remain readable.

Do not add another CSS framework or per-page header sizing rules. Use the shared `--ui-header-height`, `--ui-control-height`, `--ui-panel-radius` and `--ui-page-gutter` tokens. This is an application-owned package, not a separately released Composer library; controllers, data services and production actions stay in the application.
