import { DeleteOutlined, InboxOutlined, ReadOutlined, RocketOutlined } from '@ant-design/icons';
import { Link, router } from '@inertiajs/react';
import { Alert, App, Button, Popconfirm, Tag, Upload } from 'antd';
import type { UploadFile } from 'antd';
import { useState } from 'react';

import AppLayout from '@/layouts/AppLayout';
import { languageLabel } from '@/lib/languages';
import type { BookSummary } from '@/types';

const { Dragger } = Upload;

interface Props {
    books: BookSummary[];
    accountsCount: number;
}

export default function Library({ books, accountsCount }: Props) {
    const { message } = App.useApp();
    const [file, setFile] = useState<File | null>(null);
    const [fileList, setFileList] = useState<UploadFile[]>([]);
    const [uploading, setUploading] = useState(false);
    const [progress, setProgress] = useState(0);

    const totalPages = books.reduce((total, book) => total + book.pages_count, 0);

    const importBook = () => {
        if (!file) {
            return;
        }

        setUploading(true);
        setProgress(0);

        router.post(
            '/books',
            { file },
            {
                forceFormData: true,
                onProgress: (event) => setProgress(Math.round(event?.percentage ?? 0)),
                onSuccess: () => {
                    setFile(null);
                    setFileList([]);
                    setProgress(0);
                },
                onError: (errors) => {
                    void message.error(Object.values(errors)[0] ?? 'Importazione non riuscita.');
                },
                onFinish: () => setUploading(false),
            },
        );
    };

    return (
        <AppLayout>
            <section className="mb-6 overflow-hidden rounded-2xl border border-parchment-200 bg-gradient-to-br from-white via-parchment-50 to-leather-100 p-6 md:p-8">
                <div className="grid gap-8 lg:grid-cols-[1.25fr_1fr] lg:items-center">
                    <div>
                        <Tag color="gold" className="mb-3">
                            Traduzione e confronto affiancato
                        </Tag>
                        <h1 className="max-w-2xl font-reading text-3xl leading-tight text-ink-900 md:text-4xl">
                            Leggi l’originale accanto a una o due traduzioni. Scegli con i tuoi occhi.
                        </h1>
                        <p className="mt-3 max-w-2xl text-sm leading-relaxed text-ink-700 md:text-base">
                            Carica un EPUB, seleziona pagine, capitoli o l’intero libro e confronta i
                            risultati di account e modelli diversi. Le traduzioni già ottenute restano in
                            cache e si riaprono all’istante.
                        </p>

                        <div className="mt-5 flex flex-wrap gap-3">
                            <div className="rounded-lg border border-parchment-200 bg-white/80 px-4 py-3">
                                <div className="text-2xl font-semibold text-ink-900">{books.length}</div>
                                <div className="text-xs uppercase tracking-wide text-ink-500">
                                    {books.length === 1 ? 'Libro' : 'Libri'}
                                </div>
                            </div>
                            <div className="rounded-lg border border-parchment-200 bg-white/80 px-4 py-3">
                                <div className="text-2xl font-semibold text-ink-900">{totalPages}</div>
                                <div className="text-xs uppercase tracking-wide text-ink-500">
                                    {totalPages === 1 ? 'Pagina virtuale' : 'Pagine virtuali'}
                                </div>
                            </div>
                            <div className="rounded-lg border border-parchment-200 bg-white/80 px-4 py-3">
                                <div className="text-2xl font-semibold text-ink-900">{accountsCount}</div>
                                <div className="text-xs uppercase tracking-wide text-ink-500">
                                    {accountsCount === 1 ? 'Account attivo' : 'Account attivi'}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="rounded-xl border border-parchment-200 bg-white/85 p-4 shadow-sm">
                        <div className="mb-3 flex items-center justify-between gap-2">
                            <div>
                                <h2 className="text-base font-semibold text-ink-900">Importa un EPUB</h2>
                                <p className="text-xs text-ink-500">
                                    Il testo viene diviso in pagine virtuali da circa 1.500 caratteri.
                                </p>
                            </div>
                            <ReadOutlined className="text-xl text-leather-600" />
                        </div>

                        <Dragger
                            accept=".epub,application/epub+zip"
                            maxCount={1}
                            fileList={fileList}
                            disabled={uploading}
                            beforeUpload={(selectedFile) => {
                                setFile(selectedFile);
                                setFileList([
                                    {
                                        uid: selectedFile.uid,
                                        name: selectedFile.name,
                                        size: selectedFile.size,
                                        status: 'done',
                                    },
                                ]);
                                return false;
                            }}
                            onRemove={() => {
                                setFile(null);
                                setFileList([]);
                            }}
                            className="bg-parchment-50!"
                        >
                            <p className="ant-upload-drag-icon">
                                <InboxOutlined className="text-leather-600!" />
                            </p>
                            <p className="ant-upload-text">Trascina qui il file .epub</p>
                            <p className="ant-upload-hint">Oppure fai clic per sceglierlo · max 64 MB</p>
                        </Dragger>

                        {uploading && (
                            <div className="mt-3">
                                <div className="mb-1 flex justify-between text-xs text-ink-500">
                                    <span>Importazione in corso…</span>
                                    <span>{progress}%</span>
                                </div>
                                <div className="h-1.5 overflow-hidden rounded-full bg-parchment-200">
                                    <div
                                        className="h-full rounded-full bg-leather-600 transition-all"
                                        style={{ width: `${progress}%` }}
                                    />
                                </div>
                            </div>
                        )}

                        <Button
                            type="primary"
                            size="large"
                            block
                            className="mt-3"
                            icon={<RocketOutlined />}
                            disabled={!file}
                            loading={uploading}
                            onClick={importBook}
                        >
                            Importa e apri lo studio
                        </Button>

                        {accountsCount === 0 && (
                            <Alert
                                className="mt-3"
                                type="warning"
                                showIcon
                                title="Nessun account AI attivo"
                                description={
                                    <span>
                                        <Link href="/accounts">Configura un account</Link> per poter
                                        tradurre i libri.
                                    </span>
                                }
                            />
                        )}
                    </div>
                </div>
            </section>

            <section>
                <div className="mb-4 flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h2 className="text-xl font-semibold text-ink-900">La tua libreria</h2>
                        <p className="text-sm text-ink-500">
                            {books.length === 0
                                ? 'Nessun libro importato finora.'
                                : `${books.length} ${books.length === 1 ? 'libro pronto' : 'libri pronti'} da tradurre e confrontare.`}
                        </p>
                    </div>
                </div>

                {books.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-parchment-300 bg-white/60 px-6 py-16 text-center">
                        <ReadOutlined className="text-4xl text-parchment-300" />
                        <h3 className="mt-4 text-lg font-medium text-ink-700">La libreria è vuota</h3>
                        <p className="mx-auto mt-1 max-w-md text-sm text-ink-500">
                            Importa il tuo primo EPUB per iniziare. L’originale resterà sempre visibile
                            accanto alle traduzioni.
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                        {books.map((book) => (
                            <article
                                key={book.id}
                                className="flex flex-col overflow-hidden rounded-xl border border-parchment-200 bg-white shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-md"
                            >
                                <div className="relative h-52 overflow-hidden bg-gradient-to-br from-parchment-100 to-parchment-200">
                                    {book.cover_url ? (
                                        <img
                                            src={book.cover_url}
                                            alt={`Copertina di ${book.title}`}
                                            className="h-full w-full object-cover"
                                            loading="lazy"
                                        />
                                    ) : (
                                        <div className="flex h-full flex-col items-center justify-center gap-3 text-leather-600">
                                            <ReadOutlined className="text-4xl opacity-60" />
                                            <span className="max-w-[80%] text-center font-reading text-lg leading-snug text-ink-700">
                                                {book.title}
                                            </span>
                                        </div>
                                    )}
                                    <Tag className="absolute left-3 top-3" color="default">
                                        {languageLabel(book.language)}
                                    </Tag>
                                </div>

                                <div className="flex flex-1 flex-col p-4">
                                    <h3 className="line-clamp-2 font-reading text-lg leading-snug text-ink-900">
                                        {book.title}
                                    </h3>
                                    <p className="mt-0.5 line-clamp-1 text-sm text-ink-500">
                                        {book.author ?? 'Autore sconosciuto'}
                                    </p>

                                    <div className="mt-3 flex flex-wrap gap-1.5">
                                        <Tag>
                                            {book.chapters_count}{' '}
                                            {book.chapters_count === 1 ? 'capitolo' : 'capitoli'}
                                        </Tag>
                                        <Tag>
                                            {book.pages_count}{' '}
                                            {book.pages_count === 1 ? 'pagina' : 'pagine'}
                                        </Tag>
                                        <Tag color="gold">→ {languageLabel(book.target_language)}</Tag>
                                    </div>

                                    <div className="mt-auto flex items-center gap-2 pt-4">
                                        <Button
                                            type="primary"
                                            block
                                            onClick={() => router.visit(`/books/${book.id}`)}
                                        >
                                            Apri studio
                                        </Button>
                                        <Popconfirm
                                            title="Eliminare questo libro?"
                                            description="Capitoli, pagine e traduzioni collegate verranno rimossi."
                                            okText="Elimina"
                                            cancelText="Annulla"
                                            okButtonProps={{ danger: true }}
                                            onConfirm={() =>
                                                router.delete(`/books/${book.id}`, { preserveScroll: true })
                                            }
                                        >
                                            <Button danger icon={<DeleteOutlined />} aria-label="Elimina libro" />
                                        </Popconfirm>
                                    </div>
                                </div>
                            </article>
                        ))}
                    </div>
                )}
            </section>
        </AppLayout>
    );
}
