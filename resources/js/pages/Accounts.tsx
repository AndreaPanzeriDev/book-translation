import {
    ApiOutlined,
    CloudServerOutlined,
    DeleteOutlined,
    EditOutlined,
    KeyOutlined,
    PlusOutlined,
    ReloadOutlined,
} from '@ant-design/icons';
import { router } from '@inertiajs/react';
import { Alert, Button, Form, Input, Modal, Popconfirm, Select, Switch, Tag, Tooltip } from 'antd';
import { useState } from 'react';

import AppLayout from '@/layouts/AppLayout';
import { PROVIDER_PRESETS, providerLabel } from '@/lib/languages';
import type { AiAccount } from '@/types';

interface Props {
    accounts: AiAccount[];
}

interface FormValues {
    name: string;
    provider: string;
    base_url: string;
    api_key?: string;
    default_model?: string;
    is_active: boolean;
    [key: string]: string | boolean | undefined;
}

export default function Accounts({ accounts }: Props) {
    const [form] = Form.useForm<FormValues>();
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
                    <h1 className="font-reading text-3xl text-ink-900">Account AI</h1>
                    <p className="mt-1 max-w-2xl text-sm text-ink-500">
                        Collega OpenAI, OpenRouter, Ollama o qualsiasi server compatibile con l’API
                        OpenAI. Puoi tenere più account attivi e confrontarli nella stessa pagina.
                    </p>
                </div>
                <Button type="primary" size="large" icon={<PlusOutlined />} onClick={openCreate}>
                    Nuovo account
                </Button>
            </div>

            <Alert
                className="mb-5"
                type="info"
                showIcon
                title={`${activeCount} ${activeCount === 1 ? 'account attivo' : 'account attivi'} · ${accounts.length} ${accounts.length === 1 ? 'configurato' : 'configurati'}`}
                description="Le chiavi API vengono cifrate con la chiave dell’applicazione e non vengono mai inviate al browser. Dopo aver salvato un account, usa “Sincronizza modelli” per caricare l’elenco disponibile."
            />

            {accounts.length === 0 ? (
                <div className="rounded-xl border border-dashed border-parchment-300 bg-white/60 px-6 py-16 text-center">
                    <CloudServerOutlined className="text-4xl text-parchment-300" />
                    <h3 className="mt-4 text-lg font-medium text-ink-700">Nessun account configurato</h3>
                    <p className="mx-auto mt-1 max-w-md text-sm text-ink-500">
                        Aggiungi un provider per iniziare a tradurre. Per Ollama locale usa
                        <code className="mx-1 rounded bg-parchment-100 px-1.5 py-0.5 text-xs">
                            http://host.docker.internal:11434/v1
                        </code>
                        e lascia vuota la chiave.
                    </p>
                    <Button type="primary" className="mt-4" icon={<PlusOutlined />} onClick={openCreate}>
                        Configura il primo account
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
                                                    {account.is_active ? 'Attivo' : 'Disattivato'}
                                                </Tag>
                                                {account.has_api_key ? (
                                                    <Tag icon={<KeyOutlined />} color="gold">
                                                        Chiave salvata
                                                    </Tag>
                                                ) : (
                                                    <Tag>Nessuna chiave</Tag>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <dl className="mt-4 space-y-2 text-sm">
                                    <div>
                                        <dt className="text-xs uppercase tracking-wide text-ink-500">
                                            Endpoint
                                        </dt>
                                        <dd className="truncate font-mono text-xs text-ink-700" title={account.base_url}>
                                            {account.base_url}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs uppercase tracking-wide text-ink-500">
                                            Modello predefinito
                                        </dt>
                                        <dd className="text-ink-700">
                                            {account.default_model ?? 'Non impostato'}
                                        </dd>
                                    </div>
                                </dl>

                                <div className="mt-4">
                                    <div className="mb-1.5 flex items-center justify-between text-xs text-ink-500">
                                        <span>Modelli disponibili</span>
                                        <span>{account.models.length}</span>
                                    </div>
                                    {account.models.length === 0 ? (
                                        <p className="rounded-md bg-parchment-100/70 px-2.5 py-2 text-xs text-ink-500">
                                            Nessun modello sincronizzato. Puoi comunque digitare un
                                            modello nello studio.
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
                                        Sincronizza modelli
                                    </Button>
                                    <Button
                                        size="small"
                                        icon={<ApiOutlined />}
                                        loading={busy === 'test'}
                                        onClick={() => runAction(account, 'test')}
                                    >
                                        Verifica
                                    </Button>
                                    <div className="ml-auto flex items-center gap-1">
                                        <Button
                                            size="small"
                                            type="text"
                                            icon={<EditOutlined />}
                                            aria-label="Modifica account"
                                            onClick={() => openEdit(account)}
                                        />
                                        <Popconfirm
                                            title="Eliminare questo account?"
                                            description="Le traduzioni già salvate restano archiviate, ma non saranno più confrontabili senza l’account."
                                            okText="Elimina"
                                            cancelText="Annulla"
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
                                                aria-label="Elimina account"
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
                title={editing ? 'Modifica account' : 'Nuovo account AI'}
                open={open}
                onCancel={() => {
                    setOpen(false);
                    setEditing(null);
                }}
                onOk={() => form.submit()}
                okText="Salva"
                cancelText="Annulla"
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
                        label="Nome"
                        rules={[{ required: true, message: 'Inserisci un nome.' }]}
                    >
                        <Input placeholder="Es. OpenAI principale, Ollama locale…" />
                    </Form.Item>

                    <Form.Item name="provider" label="Provider" rules={[{ required: true }]}>
                        <Select
                            options={PROVIDER_PRESETS.map((preset) => ({
                                value: preset.value,
                                label: preset.label,
                            }))}
                            onChange={(provider: string) => {
                                const preset = PROVIDER_PRESETS.find((item) => item.value === provider);

                                if (preset?.baseUrl) {
                                    form.setFieldValue('base_url', preset.baseUrl);
                                }
                            }}
                        />
                    </Form.Item>

                    <Form.Item
                        name="base_url"
                        label="URL di base (endpoint compatibile OpenAI)"
                        rules={[
                            { required: true, message: 'Inserisci l’URL di base.' },
                            { type: 'url', message: 'URL non valido.' },
                        ]}
                        extra="Deve includere /v1, ad esempio https://api.openai.com/v1"
                    >
                        <Input placeholder="https://api.openai.com/v1" />
                    </Form.Item>

                    <Form.Item
                        name="api_key"
                        label="Chiave API"
                        extra={
                            editing?.has_api_key
                                ? 'Lascia vuoto per mantenere la chiave attuale.'
                                : 'Per Ollama locale o server senza autenticazione puoi lasciare vuoto.'
                        }
                    >
                        <Input.Password
                            placeholder={editing?.has_api_key ? '•••••••• (salvata)' : 'sk-…'}
                            autoComplete="new-password"
                        />
                    </Form.Item>

                    <Form.Item name="default_model" label="Modello predefinito">
                        <Input placeholder="Es. gpt-4o-mini, openai/gpt-4o-mini, llama3.1:8b" />
                    </Form.Item>

                    <Form.Item name="is_active" label="Account attivo" valuePropName="checked">
                        <Switch checkedChildren="Sì" unCheckedChildren="No" />
                    </Form.Item>

                    <Button htmlType="submit" className="hidden" />
                </Form>
            </Modal>
        </AppLayout>
    );
}
