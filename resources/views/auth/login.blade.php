<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in - Masaar</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full mx-4">
        <div class="bg-white rounded-lg shadow-lg p-8">
            <div class="text-center mb-8">
                <h1 class="text-2xl font-bold text-gray-800">Masaar</h1>
                <p class="text-gray-500 mt-2">Sign in to continue</p>
            </div>

            @if ($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-6 text-sm">
                {{ $errors->first() }}
            </div>
            @endif

            @if (session('status'))
            <div class="bg-blue-50 border border-blue-200 text-blue-700 px-4 py-3 rounded mb-6 text-sm">
                {{ session('status') }}
            </div>
            @endif

            <form method="POST" action="{{ route('login.attempt') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input id="email" name="email" type="email" required autofocus
                           value="{{ old('email') }}" autocomplete="username"
                           class="w-full border rounded-lg px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <input id="password" name="password" type="password" required
                           autocomplete="current-password"
                           class="w-full border rounded-lg px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <label class="flex items-center text-sm text-gray-600">
                    <input type="checkbox" name="remember" value="1" class="mr-2 text-blue-600">
                    Remember me
                </label>

                <button type="submit"
                        class="w-full py-3 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 transition-colors">
                    Sign in
                </button>
            </form>
        </div>

        <p class="text-center text-xs text-gray-400 mt-6">Powered by Masaar</p>
    </div>
</body>
</html>
