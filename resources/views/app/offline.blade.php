{{-- Kept by the service worker (public/sw.js) and shown without a connection: the camera still works,
     photos wait on the phone. No personal data and no session token in here. --}}
@include('app.camera', ['offline' => true])
