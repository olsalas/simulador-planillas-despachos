<?php

namespace App\Domain\Routing\Support;

use InvalidArgumentException;

final class FlexiblePolylineDecoder
{
    private const ENCODING_TABLE = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

    /**
     * @return list<array{lat: float, lng: float}>
     */
    public function decode(string $encoded): array
    {
        if ($encoded === '') {
            return [];
        }

        $index = 0;
        $version = $this->decodeUnsignedVarint($encoded, $index);
        if ($version !== 1) {
            throw new InvalidArgumentException('Unsupported HERE flexible polyline version.');
        }

        $header = $this->decodeUnsignedVarint($encoded, $index);
        $precision = $header & 15;
        $thirdDim = ($header >> 4) & 7;

        $factor = 10 ** $precision;

        $lat = 0;
        $lng = 0;
        $coordinates = [];

        while ($index < strlen($encoded)) {
            $lat += $this->decodeSignedVarint($encoded, $index);
            $lng += $this->decodeSignedVarint($encoded, $index);

            if ($thirdDim !== 0) {
                $this->decodeSignedVarint($encoded, $index);
            }

            $coordinates[] = [
                'lat' => $lat / $factor,
                'lng' => $lng / $factor,
            ];
        }

        return $coordinates;
    }

    private function decodeUnsignedVarint(string $encoded, int &$index): int
    {
        $result = 0;
        $shift = 0;
        $length = strlen($encoded);

        while ($index < $length) {
            $value = strpos(self::ENCODING_TABLE, $encoded[$index]);

            if ($value === false) {
                throw new InvalidArgumentException('Invalid HERE flexible polyline encoding.');
            }

            $index++;
            $result |= ($value & 0x1f) << $shift;

            if (($value & 0x20) === 0) {
                return $result;
            }

            $shift += 5;
        }

        throw new InvalidArgumentException('Unexpected end of HERE flexible polyline string.');
    }

    private function decodeSignedVarint(string $encoded, int &$index): int
    {
        $value = $this->decodeUnsignedVarint($encoded, $index);

        return ($value & 1) ? ~($value >> 1) : ($value >> 1);
    }
}
