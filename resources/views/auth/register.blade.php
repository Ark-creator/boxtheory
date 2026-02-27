<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account | GoldLogic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #0B1120; }
        .gold-gradient { background: linear-gradient(135deg, #BF953F, #FCF6BA, #B38728, #FBF5B7, #AA771C); }
        .glass { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.1); }
    </style>
</head>
<body class="text-white min-h-screen flex items-center justify-center p-6">
    <div class="absolute inset-0 z-0 overflow-hidden pointer-events-none">
        <div class="absolute top-0 -left-20 w-96 h-96 bg-amber-500/10 rounded-full blur-[120px]"></div>
    </div>

    <div class="w-full max-w-md relative z-10">
        <div class="text-center mb-10">
            <a href="/" class="inline-block mb-6">
                <div class="w-12 h-12 gold-gradient rounded-xl flex items-center justify-center mx-auto shadow-lg shadow-amber-500/20">
                    <svg class="w-7 h-7 text-[#0B1120]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg>
                </div>
            </a>
            <h1 class="text-3xl font-black italic tracking-tighter uppercase">START YOUR <span class="text-amber-400">JOURNEY</span></h1>
            <p class="text-gray-400 text-sm mt-2">Create your account to browse our XAU/USD strategies.</p>
        </div>

        <div class="glass p-8 rounded-[2rem] shadow-2xl">
            <form method="POST" action="{{ route('register') }}">
                @csrf
                <div class="space-y-5">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-2">Full Name</label>
                        <input type="text" name="name" value="{{ old('name') }}" required autofocus class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-amber-500 transition">
                        @if($errors->has('name')) <p class="text-red-400 text-xs mt-1">{{ $errors->first('name') }}</p> @endif
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-2">Email Address</label>
                        <input type="email" name="email" value="{{ old('email') }}" required class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-amber-500 transition">
                        @if($errors->has('email')) <p class="text-red-400 text-xs mt-1">{{ $errors->first('email') }}</p> @endif
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-2">Password</label>
                        <input type="password" name="password" required class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-amber-500 transition">
                        @if($errors->has('password')) <p class="text-red-400 text-xs mt-1">{{ $errors->first('password') }}</p> @endif
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-2">Confirm Password</label>
                        <input type="password" name="password_confirmation" required class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-amber-500 transition">
                    </div>

                    <button type="submit" class="w-full gold-gradient text-black font-black py-4 rounded-xl uppercase tracking-widest hover:scale-[1.02] transition transform mt-4">
                        Register Account
                    </button>
                </div>
            </form>
        </div>
        
        <p class="text-center mt-8 text-sm text-gray-500">
            Already have an account? <a href="{{ route('login') }}" class="text-white font-bold hover:text-amber-400 transition">Sign In</a>
        </p>
    </div>
</body>
</html>