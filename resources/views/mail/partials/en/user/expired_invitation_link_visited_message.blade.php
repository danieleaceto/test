Hi {{ $fullName }}.

the invited user {{ $invitedFullName }} tried to activated his/her account
to the portal [{{ config('app.name') }}]({{ url('/') }}), but his/her
invitation link is no longer valid.

You can send a new one by accessing his [profile]({{ $profileUrl }}.
