@auth
<x-layouts.app heading="Access restricted" subheading="Your current role does not allow this action">
<section class="panel access-restricted" role="alert" aria-labelledby="access-restricted-title">
<span class="access-error-code">403 &middot; Permission required</span>
<h2 id="access-restricted-title">You do not have access to this feature</h2>
<p>{{ $exception->getMessage() ?: 'Your role does not include permission to open this page or perform this action.' }}</p>
<p>Contact an administrator if you need access. They can update your assigned role and feature permissions.</p>
<div class="admin-actions"><a class="primary" href="{{route('dashboard')}}">Back to Overview</a><a href="{{route('profile')}}">View your profile</a></div>
</section>
</x-layouts.app>
@else
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Access restricted</title>@include('partials.styles')</head><body><main class="panel access-restricted"><h1>Access restricted</h1><p>Sign in with an account that has access to this feature.</p><a href="{{route('login')}}">Sign in</a></main></body></html>
@endauth
