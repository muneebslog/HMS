/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';

const userIdMeta = document.querySelector('meta[name="hms-user-id"]');
const currentUserId = userIdMeta ? Number(userIdMeta.content) : null;

window.hmsStaffOnline = window.hmsStaffOnline || {};

/**
 * Replace the staff online map and notify listeners (chat UI).
 */
function setStaffOnline(usersById) {
    window.hmsStaffOnline = usersById;
    document.dispatchEvent(new CustomEvent('hms-staff-online', {
        detail: { online: { ...window.hmsStaffOnline } },
    }));
}

if (currentUserId && window.Echo) {
    if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
        Notification.requestPermission().catch(() => {});
    }

    window.Echo.join('hms.staff')
        .here((users) => {
            setStaffOnline(Object.fromEntries(users.map((user) => [String(user.id), user])));
        })
        .joining((user) => {
            setStaffOnline({
                ...window.hmsStaffOnline,
                [String(user.id)]: user,
            });
        })
        .leaving((user) => {
            const next = { ...window.hmsStaffOnline };
            delete next[String(user.id)];
            setStaffOnline(next);
        })
        .error((error) => {
            console.warn('Unable to join staff presence channel.', error);
        });

    const channel = window.Echo.private('hms.reception');

    channel.listen('.memo.posted', (event) => {
        if (Number(event.actor_id) === currentUserId) {
            return;
        }

        notifyBrowser(
            'New Reception Memo',
            `${event.actor_name}: ${event.title}`,
            `memo-${event.memo_id}`,
        );
    });

    channel.listen('.report.posted', (event) => {
        if (Number(event.actor_id) === currentUserId) {
            return;
        }

        const title = event.is_new_thread ? 'New Report to Admin' : 'Report Reply';

        notifyBrowser(
            title,
            `${event.actor_name}: ${event.subject}`,
            `report-${event.report_id}`,
        );
    });

    window.Echo.private(`App.Models.User.${currentUserId}`).listen('.chat.message', (event) => {
        if (Number(event.sender_id) === currentUserId) {
            return;
        }

        notifyBrowser(
            'New Chat Message',
            `${event.sender_name}: ${event.body}`,
            `chat-${event.conversation_id}`,
        );
    });
}

/**
 * Show an in-app toast and, when permitted, a native desktop notification.
 */
function notifyBrowser(title, body, tag) {
    document.dispatchEvent(new CustomEvent('toast-show', {
        detail: {
            duration: 8000,
            slots: {
                heading: title,
                text: body,
            },
            dataset: {
                variant: 'warning',
            },
        },
    }));

    if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
        try {
            new Notification(title, { body, tag });
        } catch (error) {
            console.warn('Unable to show desktop notification.', error);
        }
    }
}
