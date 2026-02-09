<?php

namespace App\Http\Controllers\Ingestion;

use App\Domain\Ingestion\CsvIngestionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ingestion\UploadCsvRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class UploadCsvController extends Controller
{
    public function __construct(private readonly CsvIngestionService $csvIngestionService)
    {
    }

    public function create(): Response
    {
        return Inertia::render('Ingestion/UploadCsv');
    }

    public function store(UploadCsvRequest $request): RedirectResponse
    {
        try {
            $result = $this->csvIngestionService->import(
                $request->file('file'),
                $request->string('type')->toString(),
                $request->user()?->id
            );
        } catch (Throwable $exception) {
            report($exception);

            return to_route('ingestion.upload')
                ->with('error', 'No se pudo procesar el archivo CSV.');
        }

        return to_route('ingestion.upload')
            ->with('success', 'Carga procesada correctamente.')
            ->with('importReport', $result);
    }
}
