# Lucky Bags UI theme

Shared Blade layout package registered as `lucky-theme` by AppServiceProvider. Both layout entry points render this package. Future production modules should use `<x-layouts.app>` and the existing panel, form, navigation, button and table styles in public/css/app.css. Shared partials remain in resources/views/partials; do not introduce a second CSS framework. The package is internal to this application; standalone Composer distribution and asset extraction are not yet provided.
