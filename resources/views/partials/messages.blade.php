@if(session('ok') || session('error') || $errors->any())
    <div class="app-messages">
        @if(session('ok'))<div class="flash" role="status">{{ session('ok') }}</div>@endif
        @if(session('error'))<div class="error" role="alert">{{ session('error') }}</div>@endif
        @if($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif
    </div>
@endif
