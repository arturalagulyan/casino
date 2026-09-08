@extends('frontend.layout')

@section('title', 'Sign in · '.config('frontend.brand'))

@section('content')
    <div class="mx-auto flex min-h-[80vh] max-w-md flex-col justify-center px-4">
        <div class="mb-8 text-center">
            <div class="wordmark text-3xl">{{ config('frontend.brand') }}</div>
            <p class="mt-2 text-sm text-zinc-400">Sign in to play</p>
        </div>

        <div class="rounded-2xl border border-ink-700 bg-ink-850/80 p-6 shadow-2xl sm:p-8">
            @if($errors->any())
                <div class="mb-4 rounded-lg border border-loss/40 bg-loss/10 px-4 py-3 text-sm text-loss">
                    {{ $errors->first() }}
                </div>
            @endif

            <form action="{{ route('frontend.login.attempt') }}" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label for="login" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-zinc-400">Username or email</label>
                    <input id="login" name="login" type="text" value="{{ old('login') }}" required autofocus class="field">
                </div>
                <div>
                    <label for="password" class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-zinc-400">Password</label>
                    <input id="password" name="password" type="password" required class="field">
                </div>
                <label class="flex items-center gap-2 text-sm text-zinc-400">
                    <input type="checkbox" name="remember" value="1" class="rounded border-ink-700 bg-ink-900 text-gold-500 focus:ring-gold-500">
                    Keep me signed in
                </label>
                <button type="submit" class="btn-gold w-full">Sign in</button>
            </form>
        </div>

        <p class="mt-6 text-center text-xs text-zinc-600">
            Accounts are issued by the operator. Contact support if you need access.
        </p>
    </div>
@endsection
