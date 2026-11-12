import { App, Segmented } from 'antd';
import axios from 'axios';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { errorMessage } from '@/lib/languages';
import { normalizeLocale } from '@/i18n';

export default function LanguageSwitcher() {
    const { i18n, t } = useTranslation();
    const { message } = App.useApp();
    const [saving, setSaving] = useState(false);

    const current = normalizeLocale(i18n.resolvedLanguage ?? i18n.language);

    const change = async (value: string | number) => {
        const locale = normalizeLocale(String(value));

        if (locale === current || saving) {
            return;
        }

        setSaving(true);

        try {
            await axios.patch('/locale', { locale });
            await i18n.changeLanguage(locale);
        } catch (error) {
            void message.error(errorMessage(error));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Segmented
            value={current}
            disabled={saving}
            onChange={(value) => void change(value)}
            options={[
                { label: 'IT', value: 'it' },
                { label: 'EN', value: 'en' },
            ]}
            aria-label={t('language.label')}
        />
    );
}
