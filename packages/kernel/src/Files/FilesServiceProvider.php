<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Files;

use Illuminate\Support\ServiceProvider;

/**
 * Binds the document store (T-038 plan Ruling 6): the local-disk
 * implementation, rooted at `config('files.document_root')`.
 *
 * A singleton, so every caller in a process shares one store. A deployment
 * that wants another implementation rebinds {@see DocumentStore}; no module
 * names a disk, a bucket or an SDK. The root is resolved when the store is
 * first asked for, so a misconfigured root (inside a web path) fails at the
 * first document operation with a clear refusal, not at boot for every
 * request.
 */
final class FilesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DocumentStore::class, static function ($app): DocumentStore {
            return new LocalDocumentStore((string) $app['config']->get('files.document_root'));
        });
    }
}
