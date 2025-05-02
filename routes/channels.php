<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});


// Public channel - no authorization needed
Broadcast::channel('user.status', function () {
    return true;
});
