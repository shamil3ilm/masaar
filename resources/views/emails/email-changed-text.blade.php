{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
{!! __('Hello :name,', ['name' => $name]) !!}

{!! __('The email address for your account is now :email. Sign-in links and notices go there from now on.', ['email' => $newEmail]) !!}

{!! __('If you did not make this change, contact support straight away.') !!}
