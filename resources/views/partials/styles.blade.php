{{-- Keep the initial render styled even when a browser caches or fails a CSS request.
     public/css/app.css remains the single source of truth. --}}
<style id="app-styles">{!! file_get_contents(public_path('css/app.css')) !!}</style>
