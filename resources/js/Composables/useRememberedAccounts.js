import { ref } from 'vue';

const STORAGE_KEY = 'imis.remembered-accounts';
const MAX_ACCOUNTS = 5;

// Only a display name, username and photo path are kept, never a password or token.
const read = () => {
    try {
        const parsed = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? '[]');

        return Array.isArray(parsed)
            ? parsed.filter((a) => a && typeof a.username === 'string' && typeof a.name === 'string')
            : [];
    } catch {
        return [];
    }
};

const write = (accounts) => {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(accounts));
    } catch {
        // Storage blocked (private window): the cards simply don't persist.
    }
};

// The photo's path only, so it still loads if IMIS is opened by another host name.
const photoPath = (url) => {
    if (!url) return null;

    try {
        const { pathname, search } = new URL(url, window.location.origin);

        return pathname + search;
    } catch {
        return null;
    }
};

/**
 * Keeps an already-saved card current (name, photo) whenever that person is
 * signed in; does nothing for people who haven't saved their account here.
 */
export function refreshRememberedAccount(user) {
    const saved = read();
    if (!user || !saved.some((a) => a.username === user.username)) return;

    write(
        saved.map((a) =>
            a.username === user.username
                ? { name: user.name, username: user.username, photoUrl: photoPath(user.profile_photo_url) }
                : a,
        ),
    );
}

export function useRememberedAccounts() {
    const accounts = ref(read());

    const remember = ({ name, username, photoUrl = null }) => {
        accounts.value = [
            { name, username, photoUrl: photoPath(photoUrl) },
            ...accounts.value.filter((a) => a.username !== username),
        ].slice(0, MAX_ACCOUNTS);
        write(accounts.value);
    };

    const forget = (username) => {
        accounts.value = accounts.value.filter((a) => a.username !== username);
        write(accounts.value);
    };

    const forgetAll = () => {
        accounts.value = [];
        write([]);
    };

    return { accounts, remember, forget, forgetAll };
}
