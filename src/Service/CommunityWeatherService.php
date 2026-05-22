<?php

namespace App\Service;

use DateTimeImmutable;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class CommunityWeatherService
{
    private const ENDPOINT = 'https://api.open-meteo.com/v1/forecast';
    private const DEFAULT_LATITUDE = 36.8065;
    private const DEFAULT_LONGITUDE = 10.1815;
    private const MAX_FORECAST_DAYS = 16;

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /** @return array<string, mixed> */
    public function forecastForEvent(?\DateTimeInterface $eventDate): array
    {
        if ($eventDate === null) {
            return [
                'status' => 'unavailable',
                'message' => 'La meteo est indisponible pour cet evenement.',
            ];
        }

        $day = DateTimeImmutable::createFromInterface($eventDate)->setTime(0, 0);
        $today = new DateTimeImmutable('today');
        $limit = $today->modify('+'.self::MAX_FORECAST_DAYS.' days');
        $daysUntilEvent = (int) $today->diff($day)->format('%r%a');

        if ($day < $today) {
            return [
                'status' => 'past',
                'message' => 'Cet evenement est deja passe, la prevision n est plus disponible.',
                'days_until_event' => $daysUntilEvent,
            ];
        }

        if ($day > $limit) {
            $daysUntilAvailable = max(1, $daysUntilEvent - self::MAX_FORECAST_DAYS);

            return [
                'status' => 'pending',
                'message' => $daysUntilAvailable === 1
                    ? 'La meteo sera disponible demain. Open-Meteo couvre seulement les 16 prochains jours.'
                    : sprintf('La meteo sera disponible dans %d jours. Open-Meteo couvre seulement les 16 prochains jours.', $daysUntilAvailable),
                'days_until_available' => $daysUntilAvailable,
                'days_until_event' => $daysUntilEvent,
            ];
        }

        try {
            $forecast = $this->fetchDaily($day);

            return [
                'status' => 'available',
                'label' => $this->labelFromCode($forecast['weather_code']),
                'temperature_range' => sprintf('%.0f C a %.0f C', $forecast['temperature_min'], $forecast['temperature_max']),
                'rain_label' => sprintf('Pluie %d%%', $forecast['rain_probability']),
                'source' => 'Open-Meteo',
                'days_until_event' => $daysUntilEvent,
            ];
        } catch (Throwable) {
            return [
                'status' => 'unavailable',
                'message' => 'Le service meteo est temporairement indisponible.',
                'days_until_event' => $daysUntilEvent,
            ];
        }
    }

    /** @return array<string, float|int> */
    private function fetchDaily(DateTimeImmutable $day): array
    {
        $options = [
            'query' => [
                'latitude' => self::DEFAULT_LATITUDE,
                'longitude' => self::DEFAULT_LONGITUDE,
                'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_probability_max,weathercode',
                'start_date' => $day->format('Y-m-d'),
                'end_date' => $day->format('Y-m-d'),
                'timezone' => 'auto',
            ],
            'timeout' => 8,
        ];

        try {
            return $this->decodeForecast('GET', self::ENDPOINT, $options);
        } catch (Throwable $exception) {
            if (!$this->isCertificateIssue($exception)) {
                throw $exception;
            }

            $options['verify_peer'] = false;
            $options['verify_host'] = false;

            return $this->decodeForecast('GET', self::ENDPOINT, $options);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, float|int>
     */
    private function decodeForecast(string $method, string $url, array $options): array
    {
        $response = $this->httpClient->request($method, $url, $options);
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Weather request failed.');
        }

        $payload = $response->toArray(false);
        $daily = $payload['daily'] ?? null;

        if (!is_array($daily)) {
            throw new RuntimeException('Weather response missing daily data.');
        }

        return [
            'temperature_max' => (float) ($daily['temperature_2m_max'][0] ?? 0),
            'temperature_min' => (float) ($daily['temperature_2m_min'][0] ?? 0),
            'rain_probability' => (int) ($daily['precipitation_probability_max'][0] ?? 0),
            'weather_code' => (int) ($daily['weathercode'][0] ?? -1),
        ];
    }

    private function isCertificateIssue(Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'certificate')
            || str_contains($message, 'issuer')
            || str_contains($message, 'ssl')
            || str_contains($message, 'tls');
    }

    private function labelFromCode(int $code): string
    {
        return match (true) {
            $code === 0 => 'Ciel degage',
            $code === 1 || $code === 2 => 'Partiellement nuageux',
            $code === 3 => 'Couvert',
            $code >= 51 && $code <= 67 => 'Bruine ou pluie',
            $code >= 71 && $code <= 77 => 'Neige',
            $code >= 80 && $code <= 82 => 'Averses',
            $code >= 95 => 'Orage',
            default => 'Conditions variables',
        };
    }
}