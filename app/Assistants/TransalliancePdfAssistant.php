<?php

namespace App\Assistants;

use Carbon\Carbon;

class TransalliancePdfAssistant extends PdfClient
{
    public static function validateFormat(array $lines) {
        $hay = strtoupper(implode(" ", $lines));
        return str_contains($hay, 'TRANSALLIANCE') || str_contains($hay, 'CHARTERING CONFIRMATION') || str_contains($hay, 'FUSM');
    }

    public function processLines(array $lines, ?string $attachment_filename = null) {
        $text = implode("\n", $lines);

        $cleanLine = function($l) {
            $l = preg_replace('/\s+/', ' ', $l);
            return trim($l);
        };

        $extractDate = function($blockLines) {
            foreach ($blockLines as $ln) {
                if (preg_match('/(\d{2}\/\d{2}\/\d{2,4})/', $ln, $m)) {
                    $d = $m[1];
                    try {
                        if (strlen(explode('/', $d)[2]) == 2) {
                            return Carbon::createFromFormat('d/m/y', $d)->toIsoString();
                        } else {
                            return Carbon::createFromFormat('d/m/Y', $d)->toIsoString();
                        }
                    } catch (\Exception $e) {}
                }
            }
            return null;
        };

        $extractPostal = function($blockLines) {
            foreach ($blockLines as $ln) {
                if (preg_match('/\b(\d{5})\b/', $ln, $m)) return $m[1];
                if (preg_match('/\b([A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2})\b/i', $ln, $m)) return strtoupper(trim($m[1]));
            }
            return null;
        };

        $intro_comments = [];
        $forwarder_info = [];

        for ($i=0;$i<min(10,count($lines));$i++) {
            if (stripos($lines[$i],'TRANSALLIANCE') !== false || stripos($lines[$i],'CHARTERING') !== false) {
                for ($k=$i;$k<min(count($lines), $i+6);$k++) $forwarder_info[] = trim($lines[$k]);
                break;
            }
        }

        $order_reference = null;
        if (preg_match('/REF[:\.\s]*\s*([A-Za-z0-9\-\/]+)/i', $text, $m)) $order_reference = trim($m[1]);

        $freight_price = null;
        if (preg_match('/([\d\.,]+)\s*EUR/i', $text, $m)) $freight_price = uncomma($m[1]);

        $loading_locations = [];
        $destination_locations = [];

        for ($i=0;$i<count($lines);$i++) {
            $ln = $lines[$i];
            if (stripos($ln,'Loading ON') !== false || stripos($ln,'Loading') !== false) {
                $block = [];
                for ($k=$i; $k<=min($i+6,count($lines)-1); $k++) $block[] = $lines[$k];
                $filtered = [];
                foreach ($block as $b) {
                    $bb = trim($b);
                    if ($bb === '') continue;
                    if (preg_match('/^\s*(Please find below|Please find|Booking|Booking reference|Ref|Reference)/i', $bb)) {
                        $intro_comments[] = $bb;
                        continue;
                    }
                    $filtered[] = $bb;
                }
                if (empty($filtered)) continue;
                $company = $filtered[0];
                if (preg_match('/\bDP\s*WORLD\b/i', $company)) $company = 'DP WORLD LONDON GATEWAY';
                $postal = $extractPostal($filtered);
                $date = $extractDate($filtered);
                $entry = ['company_address' => ['company' => $cleanLine($company)]];
                if ($postal) $entry['company_address']['postal_code'] = $postal;
                if ($date) $entry['time'] = ['datetime_from' => $date];
                foreach ($filtered as $b) {
                    if (preg_match('/\b(street|road|lane|rue|avenue|drive|way|rd\.|st\.)\b/i', $b)) { $entry['company_address']['street_address'] = $cleanLine($b); break; }
                }
                $loading_locations[] = $entry;
            }

            if (stripos($ln,'Delivery ON') !== false || stripos($ln,'Delivery') !== false) {
                $block = [];
                for ($k=$i; $k<=min($i+6,count($lines)-1); $k++) $block[] = $lines[$k];
                $filtered = [];
                foreach ($block as $b) {
                    $bb = trim($b);
                    if ($bb === '') continue;
                    if (preg_match('/^\s*(Please find below|Please find|Booking|Booking reference|Ref|Reference)/i', $bb)) {
                        $intro_comments[] = $bb;
                        continue;
                    }
                    $filtered[] = $bb;
                }
                if (empty($filtered)) continue;
                $company = $filtered[0];
                $postal = $extractPostal($filtered);
                $date = $extractDate($filtered);
                $entry = ['company_address' => ['company' => $cleanLine($company)]];
                if ($postal) $entry['company_address']['postal_code'] = $postal;
                if ($date) $entry['time'] = ['datetime_from' => $date];
                foreach ($filtered as $b) {
                    if (preg_match('/\b(street|road|lane|rue|avenue|drive|way|rd\.|st\.)\b/i', $b)) { $entry['company_address']['street_address'] = $cleanLine($b); break; }
                }
                $destination_locations[] = $entry;
            }
        }

        if (empty($loading_locations)) $loading_locations[] = ['company_address' => ['company' => 'N/A']];
        if (empty($destination_locations)) $destination_locations[] = ['company_address' => ['company' => 'N/A']];

        $cargos = [];
        $pallets = 0;
        if (preg_match('/Pal\.?\s*nb\.?\s*[:\s]*([0-9]+)/i', $text, $m)) $pallets = (int)$m[1];
        elseif (preg_match_all('/(\d+)\s+pallets?/i', $text, $pm)) foreach ($pm[1] as $p) $pallets += (int)$p;
        if ($pallets <= 0) $pallets = 1;
        $weight = null;
        if (preg_match('/Weight\s*[:\s]*([\d\.,]+)/i', $text, $m)) $weight = uncomma($m[1]);
        $cargos[] = ['title' => 'Packaging', 'package_count' => $pallets, 'package_type' => 'EPAL', 'type' => 'FTL', 'palletized' => true, 'weight' => $weight];

        $comment_text = null;
        $all_comments = [];
        if (!empty($forwarder_info)) $all_comments[] = implode(' | ', $forwarder_info);
        if (!empty($intro_comments)) $all_comments = array_merge($all_comments, $intro_comments);
        if (!empty($all_comments)) $comment_text = implode('; ', $all_comments);

        $data = [
            'attachment_filenames' => [$attachment_filename ?? basename($attachment_filename ?? 'unknown')],
            'customer' => ['side' => 'sender', 'details' => ['company' => 'TRANSALLIANCE TS LTD']],
            'loading_locations' => $loading_locations,
            'destination_locations' => $destination_locations,
            'cargos' => $cargos,
            'order_reference' => $order_reference ?? ('TRANSALLIANCE-' . time()),
            'freight_price' => $freight_price ?? null,
            'freight_currency' => 'EUR',
            'comment' => $comment_text,
        ];

        $this->createOrder($data);
    }
}
