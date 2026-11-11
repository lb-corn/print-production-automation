{{-- Inline the published theme for reliable first paint. Edit resources/css in ui-theme,
     then run php packages/ui-theme/build.php; public/css contains published assets only. --}}
<style id="app-styles">{!! file_get_contents(public_path('css/app.css')) !!}</style>
