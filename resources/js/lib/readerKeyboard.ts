export interface ReaderNavigation {
    canGoPrevious: boolean;
    canGoNext: boolean;
    goPrevious: () => void;
    goNext: () => void;
    drawerOpen: boolean;
}

export interface ReaderNavigationRef {
    current: ReaderNavigation;
}

interface ReaderKeyboardState {
    navigation: ReaderNavigationRef | null;
    subscribers: number;
    handler: (event: KeyboardEvent) => void;
}

interface ReaderKeyboardWindow extends Window {
    __bookReaderKeyboard?: ReaderKeyboardState;
}

function keyboardState(): ReaderKeyboardState {
    const readerWindow = window as ReaderKeyboardWindow;

    if (!readerWindow.__bookReaderKeyboard) {
        readerWindow.__bookReaderKeyboard = {
            navigation: null,
            subscribers: 0,
            handler: handleReaderKeydown,
        };
    }

    return readerWindow.__bookReaderKeyboard;
}

function handleReaderKeydown(event: KeyboardEvent): void {
    const current = keyboardState().navigation?.current;

    if (!current) {
        return;
    }

    if (
        event.defaultPrevented ||
        event.metaKey ||
        event.ctrlKey ||
        event.altKey ||
        event.shiftKey ||
        current.drawerOpen
    ) {
        return;
    }

    const target = event.target instanceof HTMLElement ? event.target : null;

    if (
        target?.closest(
            'input, textarea, select, [contenteditable="true"], .ant-select, .ant-drawer',
        )
    ) {
        return;
    }

    if (event.key === 'ArrowLeft' && current.canGoPrevious) {
        event.preventDefault();
        current.goPrevious();
    }

    if (event.key === 'ArrowRight' && current.canGoNext) {
        event.preventDefault();
        current.goNext();
    }
}

export function subscribeReaderKeyboard(navigation: ReaderNavigationRef): () => void {
    const state = keyboardState();

    state.navigation = navigation;
    state.subscribers += 1;

    if (state.subscribers === 1) {
        window.addEventListener('keydown', state.handler);
    }

    return () => {
        if (state.navigation === navigation) {
            state.navigation = null;
        }

        state.subscribers = Math.max(0, state.subscribers - 1);

        if (state.subscribers === 0) {
            window.removeEventListener('keydown', state.handler);
        }
    };
}
