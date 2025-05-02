import './bootstrap';

Echo.channel('user.status')
    .listen('UserOnlineStatusChanged', (e) => {
        console.log('User status changed:', e);
    });
