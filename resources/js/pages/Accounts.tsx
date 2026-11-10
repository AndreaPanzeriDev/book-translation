import {
    ApiOutlined,
    CloudServerOutlined,
    DeleteOutlined,
    EditOutlined,
    KeyOutlined,
    OrderedListOutlined,
    PlusOutlined,
    ReloadOutlined,
    ThunderboltOutlined,
} from '@ant-design/icons';
import { router } from '@inertiajs/react';
import {
    Alert,
    Button,
    Form,
    Input,
    InputNumber,
    Modal,
    Popconfirm,
    Radio,
    Select,
    Switch,
    Tag,
    Tooltip,
} from 'antd';
import { useState } from 'react';
import { Trans, useTranslation } from 'react-i18next';

import AppLayout from '@/layouts/AppLayout';
import { PROVIDER_PRESETS, providerLabel } from '@/lib/languages';
import type { AiAccount, RequestMode } from '@/types';

interface Props {
    accounts: AiAccount[];
}

interface FormValues {
    name: string;
    provider: string;
    base_url: string;
    api_key?: string;
    default_model?: string;
    request_mode: RequestMode;
    max_parallel_requests: number;
    is_active: boolean;
    [key: string]: string | number | boolean | undefined;
}

export default function Accounts({ accounts }: Props) {
    const { t } = useTranslation();
    const [form] = Form.useForm<FormValues>();
    const requestMode = Form.useWatch('request_mode', form);
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<AiAccount | null>(null);
    const [saving, setSaving] = useState(false);
    const [action, setAction] = useState<{ id: number; type: 'sync' | 'test' } | null>(null);

    const openCreate = () => {
        setEditing(null);
        form.resetFields();
        form.setFieldsValue({
            provider: 'openai',
            base_url: PROVIDER_PRESETS[0].baseUrl,
            request_mode: 'serial',
            max_parallel_requests: 4,
            is_active: true,
        });
        setOpen(true);
    };

    const openEdit = (account: AiAccount) => {
        setEditing(account);
        form.resetFields();
        form.setFieldsValue({
            name: account.name,
            provider: account.provider,
            base_url: account.base_url,
            api_key: '',
            default_model: account.default_model ?? '',
            request_mode: account.request_mode ?? 'serial',
            max_parallel_requests: account.max_parallel_requests,
            is_active: account.is_active,
        });
        setOpen(true);
    };

    const submit = (values: FormValues) => {
        setSaving(true);

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                setEditing(null);
                form.resetFields();
            },
            onError: (errors: Record<string, string>) => {
                form.setFields(
                    Object.entries(errors).map(([name, error]) => ({
                        name,
                        errors: [error],
                    })),
                );
            },
            onFinish: () => setSaving(false),
        };

        if (editing) {
            router.put(`/accounts/${editing.id}`, values, options);
        } else {
            router.post('/accounts', values, options);
        }
    };

    const runAction = (account: AiAccount, type: 'sync' | 'test') => {
        setAction({ id: account.id, type });
        const url =
            type === 'sync'
                ? `/accounts/${account.id}/sync-models`
                : `/accounts/${account.id}/test`;

        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onFinish: () => setAction(null),
            },
        );
    };

    const activeCount = accounts.filter((account) => account.is_active).length;

    return (
        <AppLayout>
            <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 className="font-reading text-3xl text-ink-900">{t('accounts.title')}</h1>
                    <p className="mt-1 max-w-2xl text-sm text-ink-500">
                        {t('accounts.description')}
                    </p>
                </div>
                <Button type="primary" size="large" icon={<PlusOutlined />} onClick={openCreate}>
                    {t('accounts.new')}
                </Button>
            </div>

            <Alert
                className="mb-5"
                type="info"
                showIcon
                title={`${t('accounts.summaryActive', { count: activeCount })} · ${t('accounts.summaryConfigured', { count: accounts.length })}`}
                description={t('accounts.alertDescription')}
            />

            {accounts.length === 0 ? (
                <div className="rounded-xl border border-dashed border-parchment-300 bg-white/60 px-6 py-16 text-center">
                    <CloudServerOutlined className="text-4xl text-parchment-300" />
                    <h3 className="mt-4 text-lg font-medium text-ink-700">
                        {t('accounts.emptyTitle')}
                    </h3>
                    <p className="mx-auto mt-1 max-w-md text-sm text-ink-500">
                        <Trans
                            i18nKey="accounts.emptyDescription"
                            components={{
                                code: (
                                    <code className="mx-1 rounded bg-parchment-100 px-1.5 py-0.5 text-xs" />
                                ),
                            }}
                        />
                    </p>
                    <Button type="primary" className="mt-4" icon={<PlusOutlined />} onClick={openCreate}>
                        {t('accounts.emptyCta')}
                    </Button>
                </div>
            ) : (
                <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                    {accounts.map((account) => {
                        const busy = action?.id === account.id ? action.type : null;
                        const shownModels = account.models.slice(0, 8);
                        const hiddenModels = account.models.length - shownModels.length;

                        return (
                            <article
                                key={account.id}
                                className="flex flex-col rounded-xl border border-parchment-200 bg-white p-5 shadow-sm"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="flex min-w-0 items-start gap-3">
                                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-leather-100 text-leather-700">
                                            <CloudServerOutlined />
                                        </span>
                                        <div className="min-w-0">
                                            <h2 className="truncate text-base font-semibold text-ink-900">
                                                {account.name}
                                            </h2>
                                            <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                                <Tag>{providerLabel(account.provider)}</Tag>
                                                <Tag color={account.is_active ? 'green' : 'default'}>
                                                    {account.is_active
                                                        ? t('accounts.statusActive')
                                                        : t('accounts.statusInactive')}
                                                </Tag>
                                                <Tag
                                                    color={
                                                        account.request_mode === 'parallel'
                                                            ? 'blue'
                                                            : 'default'
                                                    }
                                                    icon={
                                                        account.request_mode === 'parallel' ? (
                                                            <ThunderboltOutlined />
                                                        ) : (
                                                            <OrderedListOutlined />
                                                        )
                                                    }
                                                >
                                                    {account.request_mode === 'parallel'
                                                        ? t('accounts.modeParallelLimit', {
                                                              limit: account.max_parallel_requests,
                                                          })
                                                        : t('accounts.modeSerial')}
                                                </Tag>
                                                {account.has_api_key ? (
                                                    <Tag icon={<KeyOutlined />} color="gold">
                                                        {t('accounts.keySaved')}
                                                    </Tag>
                                                ) : (
                                                    <Tag>{t('accounts.keyMissing')}</Tag>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <dl className="mt-4 space-y-2 text-sm">
                                    <div>
                                        <dt className="text-xs uppercase tracking-wide text-ink-500">
                                            {t('accounts.endpoint')}
                                        </dt>
                                        <dd
                                            className="truncate font-mono text-xs text-ink-700"
                                            title={account.base_url}
                                        >
                                            {account.base_url}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs uppercase tracking-wide text-ink-500">
                                            {t('accounts.defaultModel')}
                                        </dt>
                                        <dd className="text-ink-700">
                                            {account.default_model ?? t('accounts.notSet')}
                                        </dd>
                                    </div>
                                </dl>

                                <div className="mt-4">
                                    <div className="mb-1.5 flex items-center justify-between text-xs text-ink-500">
                                        <span>{t('accounts.modelsAvailable')}</span>
                                        <span>{account.models.length}</span>
                                    </div>
                                    {account.models.length === 0 ? (
                                        <p className="rounded-md bg-parchment-100/70 px-2.5 py-2 text-xs text-ink-500">
                                            {t('accounts.modelsEmpty')}
                                        </p>
                                    ) : (
                                        <div className="flex flex-wrap gap-1">
                                            {shownModels.map((model) => (
                                                <Tooltip key={model.id} title={model.model_id}>
                                                    <Tag className="max-w-full truncate font-mono text-[11px]">
                                                        {model.model_id}
                                                    </Tag>
                                                </Tooltip>
                                            ))}
                                            {hiddenModels > 0 && <Tag>+{hiddenModels}</Tag>}
                                        </div>
                                    )}
                                </div>

                                <div className="mt-auto flex flex-wrap items-center gap-2 pt-5">
                                    <Button
                                        size="small"
                                        icon={<ReloadOutlined />}
                                        loading={busy === 'sync'}
                                        onClick={() => runAction(account, 'sync')}
                                    >
                                        {t('accounts.syncModels')}
                                    </Button>
                                    <Button
                                        size="small"
                                        icon={<ApiOutlined />}
                                        loading={busy === 'test'}
                                        onClick={() => runAction(account, 'test')}
                                    >
                                        {t('accounts.test')}
                                    </Button>
                                    <div className="ml-auto flex items-center gap-1">
                                        <Button
                                            size="small"
                                            type="text"
                                            icon={<EditOutlined />}
                                            aria-label={t('accounts.editAria')}
                                            onClick={() => openEdit(account)}
                                        />
                                        <Popconfirm
                                            title={t('accounts.deleteTitle')}
                                            description={t('accounts.deleteDescription')}
                                            okText={t('common.delete')}
                                            cancelText={t('common.cancel')}
                                            okButtonProps={{ danger: true }}
                                            onConfirm={() =>
                                                router.delete(`/accounts/${account.id}`, {
                                                    preserveScroll: true,
                                                })
                                            }
                                        >
                                            <Button
                                                size="small"
                                                type="text"
                                                danger
                                                icon={<DeleteOutlined />}
                                                aria-label={t('accounts.deleteAria')}
                                            />
                                        </Popconfirm>
                                    </div>
                                </div>
                            </article>
                        );
                    })}
                </div>
            )}

            <Modal
                title={editing ? t('accounts.modalEditTitle') : t('accounts.modalCreateTitle')}
                open={open}
                onCancel={() => {
                    setOpen(false);
                    setEditing(null);
                }}
                onOk={() => form.submit()}
                okText={t('common.save')}
                cancelText={t('common.cancel')}
                confirmLoading={saving}
                width={560}
            >
                <Form
                    form={form}
                    layout="vertical"
                    onFinish={submit}
                    requiredMark="optional"
                    className="mt-4"
                >
                    <Form.Item
                        name="name"
                        label={t('accounts.form.nameLabel')}
                        rules={[{ required: true, message: t('accounts.form.nameRequired') }]}
                    >
                        <Input placeholder={t('accounts.form.namePlaceholder')} />
                    </Form.Item>

                    <Form.Item
                        name="provider"
                        label={t('accounts.form.providerLabel')}
                        rules={[{ required: true }]}
                    >
                        <Select
                            options={PROVIDER_PRESETS.map((preset) => ({
                                value: preset.value,
                                label: providerLabel(preset.value),
                            }))}
                            onChange={(provider: string) => {
                                const preset = PROVIDER_PRESETS.find(
                                    (item) => item.value === provider,
                                );

                                if (preset?.baseUrl) {
                                    form.setFieldValue('base_url', preset.baseUrl);
                                }
                            }}
                        />
                    </Form.Item>

                    <Form.Item
                        name="base_url"
                        label={t('accounts.form.baseUrlLabel')}
                        rules={[
                            { required: true, message: t('accounts.form.baseUrlRequired') },
                            { type: 'url', message: t('accounts.form.baseUrlInvalid') },
                        ]}
                        extra={t('accounts.form.baseUrlExtra')}
                    >
                        <Input placeholder="https://api.openai.com/v1" />
                    </Form.Item>

                    <Form.Item
                        name="api_key"
                        label={t('accounts.form.apiKeyLabel')}
                        extra={
                            editing?.has_api_key
                                ? t('accounts.form.apiKeyExtraSaved')
                                : t('accounts.form.apiKeyExtraNew')
                        }
                    >
                        <Input.Password
                            placeholder={
                                editing?.has_api_key
                                    ? t('accounts.form.apiKeyPlaceholderSaved')
                                    : t('accounts.form.apiKeyPlaceholderNew')
                            }
                            autoComplete="new-password"
                        />
                    </Form.Item>

                    <Form.Item
                        name="default_model"
                        label={t('accounts.form.defaultModelLabel')}
                    >
                        <Input placeholder={t('accounts.form.defaultModelPlaceholder')} />
                    </Form.Item>

                    <Form.Item
                        name="request_mode"
                        label={t('accounts.form.requestModeLabel')}
                        extra={t('accounts.form.requestModeExtra')}
                    >
                        <Radio.Group>
                            <Radio value="serial">{t('accounts.modeSerial')}</Radio>
                            <Radio value="parallel">{t('accounts.modeParallel')}</Radio>
                        </Radio.Group>
                    </Form.Item>

                    {requestMode === 'parallel' && (
                        <Form.Item
                            name="max_parallel_requests"
                            label={t('accounts.form.maxParallelLabel')}
                            extra={t('accounts.form.maxParallelExtra')}
                            rules={[
                                {
                                    required: true,
                                    message: t('accounts.form.maxParallelRequired'),
                                },
                            ]}
                        >
                            <InputNumber
                                min={1}
                                max={50}
                                precision={0}
                                className="w-full"
                            />
                        </Form.Item>
                    )}

                    <Form.Item
                        name="is_active"
                        label={t('accounts.form.isActiveLabel')}
                        valuePropName="checked"
                    >
                        <Switch
                            checkedChildren={t('accounts.form.yes')}
                            unCheckedChildren={t('accounts.form.no')}
                        />
                    </Form.Item>

                    <Button htmlType="submit" className="hidden" />
                </Form>
            </Modal>
        </AppLayout>
    );
}
