<?php

namespace App\Domain\Planning;

use App\Models\Depot;
use App\Models\PlanningScenarioStop;
use Illuminate\Support\Str;

class BogotaScope
{
    private const LAT_MIN = 4.45;

    private const LAT_MAX = 4.90;

    private const LNG_MIN = -74.35;

    private const LNG_MAX = -73.95;

    public function cityLabel(): string
    {
        return 'Bogotá';
    }

    public function operationalCutLabel(): string
    {
        return 'Fecha + depot Bogotá';
    }

    public function operationalCutDefinition(): string
    {
        return 'En este MVP, el corte operativo es la jornada completa del depot de Bogotá para la fecha seleccionada. Se excluyen paradas fuera de Bogotá y puntos sin geocódigo.';
    }

    public function isBogotaDepot(Depot $depot): bool
    {
        return $this->matchesLocation(
            $depot->latitude,
            $depot->longitude,
            $depot->name,
            $depot->address,
            $depot->code,
        );
    }

    public function isBogotaStop(PlanningScenarioStop $stop): bool
    {
        return $this->matchesLocation(
            $stop->latitude,
            $stop->longitude,
            $stop->branch_name,
            $stop->branch_address,
            $stop->branch_code,
        );
    }

    public function matchesLocation(
        mixed $latitude,
        mixed $longitude,
        ?string $primaryLabel = null,
        ?string $secondaryLabel = null,
        ?string $code = null,
    ): bool {
        if ($this->isInsideBogotaBoundingBox($latitude, $longitude)) {
            return true;
        }

        $haystack = Str::of(implode(' ', array_filter([
            $primaryLabel,
            $secondaryLabel,
            $code,
        ])))
            ->ascii()
            ->lower()
            ->toString();

        return str_contains($haystack, 'bogota');
    }

    private function isInsideBogotaBoundingBox(mixed $latitude, mixed $longitude): bool
    {
        if ($latitude === null || $longitude === null) {
            return false;
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;

        return $lat >= self::LAT_MIN
            && $lat <= self::LAT_MAX
            && $lng >= self::LNG_MIN
            && $lng <= self::LNG_MAX;
    }
}
