import { usePage } from '@inertiajs/react';
import { App } from 'antd';
import { useEffect } from 'react';

import type { SharedProps } from '@/types';

export function useFlash(): void {
    const { message } = App.useApp();
    const { flash } = usePage<SharedProps>().props;

    useEffect(() => {
        if (flash?.success) {
            void message.success({ content: flash.success, duration: 6 });
        }

        if (flash?.error) {
            void message.error({ content: flash.error, duration: 10 });
        }
    }, [flash?.success, flash?.error, message]);
}
