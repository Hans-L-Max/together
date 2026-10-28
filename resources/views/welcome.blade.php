<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        @include('partials.head', ['title' => null])
    </head>
    <body class="min-h-full bg-stone-50 text-stone-900 antialiased dark:bg-stone-950 dark:text-stone-100">
        <main class="mx-auto flex min-h-screen max-w-xl flex-col justify-center gap-6 px-6 py-16">
            <p class="text-sm font-medium uppercase tracking-widest text-amber-700 dark:text-amber-400">
                {{ config('app.name') }}
            </p>
            <h1 class="font-serif text-4xl leading-tight sm:text-5xl">
                {{ __('Two people. One question. Answers revealed together.') }}
            </h1>
            <p class="text-lg text-stone-600 dark:text-stone-400">
                {{ __('Conversation games for people who already know each other well.') }}
            </p>
            <nav class="flex flex-wrap gap-3 pt-2">
                @auth
                    <a href="{{ route('dashboard') }}" class="rounded-full bg-stone-900 px-5 py-3 text-sm font-semibold text-white hover:bg-stone-700 dark:bg-stone-100 dark:text-stone-900">
                        {{ __('Dashboard') }}
                    </a>
                @else
                    <a href="{{ route('login') }}" class="rounded-full bg-stone-900 px-5 py-3 text-sm font-semibold text-white hover:bg-stone-700 dark:bg-stone-100 dark:text-stone-900">
                        {{ __('Log in') }}
                    </a>
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="rounded-full border border-stone-300 px-5 py-3 text-sm font-semibold hover:bg-stone-100 dark:border-stone-700 dark:hover:bg-stone-900">
                            {{ __('Register') }}
                        </a>
                    @endif
                @endauth
            </nav>
        </main>
    </body>
</html>
