<?php

namespace App\Assistants;

use Carbon\Carbon;

class ZieglerPdfAssistant extends PdfClient
{
    public static function validateFormat(array $lines) {
        $hay = strtoupper(implode(" ", $lines));
        return str_contains($hay, 'ZIEGLER') && (str_contains($hay, 'BOOKING') || str_contains($hay, 'COLLECTION'));
    }

    public function processLines(array $lines, ?string $attachment_filename = null) {
        $text = implode("\n", $lines);

        // Helper closures
        $isCompanyLine = function($l) {
            $l = trim($l);
            if ($l === '') return false;
            if (preg_match('/\b(LTD|LIMITED|PLC|GMBH|SARL|S\.A\.|CO\.|COMPANY|SOLUTIONS)\b/i', $l)) return true;
            if (mb_strtoupper($l, 'UTF-8') === $l && str_word_count($l) <= 6 && strlen($l) > 2) return true;
            if (preg_match('/^[A-Z0-9\-\&\.\' ]{3,80}$/', $l) && str_word_count($l) <= 6) return true;
            return false;
        };

        $cleanLine = function($l) {
            $l = preg_replace('/\s+/', ' ', $l);
            return trim($l);
        };

        $extractDateFromBlock = function($blockLines) {
            foreach ($blockLines as $ln) {
                if (preg_match('/(\d{2}\/\d{2}\/\d{4})/', $ln, $m)) {
                    try { return Carbon::createFromFormat('d/m/Y', $m[1])->toIsoString(); } catch (\Exception $e) {}
                }
                if (preg_match('/(\d{2}\/\d{2}\/\d{2})/', $ln, $m)) {
                    try { return Carbon::createFromFormat('d/m/y', $m[1])->toIsoString(); } catch (\Exception $e) {}
                }
            }
            return null;
        };

        $extractPostal = function($blockLines) {
            foreach ($blockLines as $ln) {
                if (preg_match('/\b([A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2})\b/i', $ln, $m)) return strtoupper(trim($m[1]));
                if (preg_match('/\b(\d{5})\b/', $ln, $m)) return $m[1];
            }
            return null;
        };

        $extractCity = function($blockLines, $postal = null) use ($isCompanyLine) {
            if ($postal) {
                foreach ($blockLines as $ln) {
                    if (stripos($ln, $postal) !== false) {
                        $parts = preg_split('/\s+/', trim($ln));
                        $idx = null;
                        foreach ($parts as $k => $p) { if (strcasecmp($p, $postal) === 0 || stripos($p, $postal) !== false) { $idx = $k; break; } }
                        if ($idx !== null && $idx > 0) { return trim($parts[$idx-1]); }
                        $candidate = trim(preg_replace('/'.preg_quote($postal, '/').'/i', '', $ln));
                        $candidate = trim(preg_replace('/\d{2,}/', '', $candidate));
                        $candidate = trim(preg_replace('/[,;:\-\/]+/', ' ', $candidate));
                        if ($candidate !== '') return $candidate;
                    }
                }
            }
            for ($i = 0; $i < count($blockLines); $i++) {
                $ln = trim($blockLines[$i]);
                if ($ln === '') continue;
                if ($isCompanyLine($ln)) {
                    for ($j = $i+1; $j < count($blockLines); $j++) {
                        $cand = trim($blockLines[$j]);
                        if ($cand === '') continue;
                        if (preg_match('/\b(Collection|Delivery|Clearance|Contact|Carrier|Ref|BOOKING|PALLETS?|Weight|Rate)\b/i', $cand)) continue;
                        if (preg_match('/\d{2}\/\d{2}\/\d{2,4}/', $cand)) continue;
                        if (preg_match('/\b(street|road|lane|rue|avenue|drive|way|rd\.|st\.)\b/i', $cand)) {
                            continue;
                        }
                        return $cand;
                    }
                }
            }
            foreach ($blockLines as $ln) {
                $cand = trim($ln);
                if ($cand === '') continue;
                if (preg_match('/\b(Collection|Delivery|Clearance|Contact|Carrier|Ref|BOOKING|PALLETS?|Weight|Rate)\b/i', $cand)) continue;
                if (preg_match('/\d{2}\/\d{2}\/\d{2,4}/', $cand)) continue;
                if (preg_match('/\b(street|road|lane|rue|avenue|drive|way|rd\.|st\.)\b/i', $cand)) continue;
                return $cand;
            }
            return null;
        };

        // Gather forwarder header (top-right) but we will not map to customer (customer is Test Client)
        $forwarder_info = [];
        for ($i=0;$i<min(12, count($lines)); $i++) {
            if (stripos($lines[$i], 'ZIEGLER') !== false || stripos($lines[$i], 'LONDON GATEWAY') !== false) {
                for ($k = $i; $k < min(count($lines), $i+6); $k++) {
                    $forwarder_info[] = trim($lines[$k]);
                }
                break;
            }
        }
        $forwarder_comment = count($forwarder_info) ? implode(' | ', $forwarder_info) : null;

        // Order reference, freight price, carrier
        $order_reference = null;
        if (preg_match('/Ziegler Ref[:\s]*([A-Za-z0-9\-\/]+)/i', $text, $m)) {
            $order_reference = trim($m[1]);
        } elseif (preg_match('/\bREF\s*[:\s\-]*\s*([A-Za-z0-9\-\/]+)/i', $text, $m)) {
            $order_reference = trim($m[1]);
        }

        $freight_price = null;
        if (preg_match('/Rate\s*[€\p{Sc}]?\s*([\d\.,]+)/iu', $text, $m)) {
            $freight_price = isset($m[1]) ? uncomma($m[1]) : null;
        } elseif (preg_match('/([0-9\.,]+)\s*EUR/i', $text, $m)) {
            $freight_price = uncomma($m[1]);
        }

        $carrier = null;
        if (preg_match('/Carrier\s*[:\s]*([^\r\n]+)/i', $text, $m)) {
            $carrier = trim($m[1]);
        } elseif (preg_match('/Carrier([A-Za-z0-9_\s]+)/i', $text, $m)) {
            $carrier = trim($m[1]);
            $carrier = preg_replace('/_/', ' ', $carrier);
        }

        // Customer detection (Test Client in middle column)
        $customer_details = ['company' => 'N/A'];
        foreach ($lines as $ln) {
            if (stripos($ln, 'Contact:') !== false) {
                $val = trim(preg_replace('/Contact\:/i', '', $ln));
                if (preg_match('/Test[_\s]*Client(?:\s*\d+)?/i', $val, $mm)) {
                    $customer_details['company'] = preg_replace('/_/', ' ', $mm[0]);
                    break;
                }
                if (preg_match('/([A-Z][a-z]+\s[A-Z][a-z]+)(.*Test[_\s]*Client.*)/', $val, $mm)) {
                    $customer_details['company'] = trim(preg_replace('/_/', ' ', $mm[2]));
                    $customer_details['contact_person'] = trim($mm[1]);
                    break;
                }
                $customer_details['company'] = $cleanLine($val);
                break;
            }
        }
        if ($customer_details['company'] === 'N/A') {
            foreach ($lines as $ln) {
                if (preg_match('/Test[_\s]*Client(?:\s*\d+)?/i', $ln, $m)) {
                    $customer_details['company'] = preg_replace('/_/', ' ', trim($m[0]));
                    break;
                }
            }
        }
        if ($customer_details['company'] === 'N/A') {
            foreach ($lines as $i => $ln) {
                if (preg_match('/Ziegler Ref/i', $ln) && isset($lines[$i+1])) {
                    $cand = trim($lines[$i+1]);
                    if ($cand && strlen($cand) < 120) {
                        $customer_details['company'] = $cleanLine($cand);
                        break;
                    }
                }
            }
        }

        // Parse loading/destination; collect discarded intro lines into $intro_comments
        $loading_locations = [];
        $destination_locations = [];
        $intro_comments = [];

        for ($i = 0; $i < count($lines); $i++) {
            $ln = $lines[$i];

            if (stripos($ln, 'Collection') !== false) {
                $block = [];
                for ($k = $i; $k <= min(count($lines)-1, $i+6); $k++) $block[] = $lines[$k];
                // filter block lines: skip obvious intro/comment lines
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
                $company = null;
                foreach ($filtered as $b) {
                    if ($isCompanyLine($b)) { $company = $cleanLine($b); break; }
                }
                if (!$company) $company = trim(preg_replace('/Collection\s*[:\-]*/i','', $filtered[0]));
                $postal = $extractPostal($filtered);
                $city = $extractCity($filtered, $postal);
                $date = $extractDateFromBlock($filtered);
                $entry = ['company_address' => ['company' => $company ?: 'N/A']];
                if ($postal) $entry['company_address']['postal_code'] = $postal;
                if ($city) $entry['company_address']['city'] = $city;
                foreach ($filtered as $b) {
                    if (preg_match('/\b(street|road|lane|rue|avenue|drive|way|rd\.|st\.)\b/i', $b)) {
                        $entry['company_address']['street_address'] = $cleanLine($b);
                        break;
                    }
                }
                if ($date) $entry['time'] = ['datetime_from' => $date];
                $loading_locations[] = $entry;
            }

            if (stripos($ln, 'Delivery') !== false) {
                $block = [];
                for ($k = $i; $k <= min(count($lines)-1, $i+6); $k++) $block[] = $lines[$k];
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
                $company = null;
                foreach ($filtered as $b) {
                    if ($isCompanyLine($b)) { $company = $cleanLine($b); break; }
                }
                if (!$company) $company = trim(preg_replace('/Delivery\s*[:\-]*/i','', $filtered[0]));
                $postal = $extractPostal($filtered);
                $city = $extractCity($filtered, $postal);
                $date = $extractDateFromBlock($filtered);
                $entry = ['company_address' => ['company' => $company ?: 'N/A']];
                if ($postal) $entry['company_address']['postal_code'] = $postal;
                if ($city) $entry['company_address']['city'] = $city;
                foreach ($filtered as $b) {
                    if (preg_match('/\b(street|road|lane|rue|avenue|drive|way|rd\.|st\.)\b/i', $b)) {
                        $entry['company_address']['street_address'] = $cleanLine($b);
                        break;
                    }
                }
                if ($date) $entry['time'] = ['datetime_from' => $date];
                $destination_locations[] = $entry;
            }
        }

        if (empty($loading_locations)) $loading_locations[] = ['company_address' => ['company' => 'N/A']];
        if (empty($destination_locations)) $destination_locations[] = ['company_address' => ['company' => 'N/A']];

        // Cargo aggregation
        $cargos = [];
        $total_pallets = 0;
        if (preg_match_all('/(\d+)\s+pallets?/i', $text, $pm)) {
            foreach ($pm[1] as $p) $total_pallets += (int) $p;
        }
        if ($total_pallets <= 0) $total_pallets = 1;
        $cargos[] = [
            'title' => 'Pallets',
            'package_count' => $total_pallets,
            'package_type' => 'EPAL',
            'type' => 'FTL',
            'palletized' => true,
        ];

        $comment_text = null;
        $all_comments = [];
        if ($forwarder_comment) $all_comments[] = $forwarder_comment;
        if (!empty($intro_comments)) $all_comments = array_merge($all_comments, $intro_comments);
        if ($carrier) $all_comments[] = 'Carrier: ' . $carrier;
        if (!empty($all_comments)) $comment_text = implode('; ', $all_comments);

        $data = [
            'attachment_filenames' => [$attachment_filename ?? basename($attachment_filename ?? 'unknown')],
            'customer' => ['side' => 'sender', 'details' => $customer_details],
            'loading_locations' => $loading_locations,
            'destination_locations' => $destination_locations,
            'cargos' => $cargos,
            'order_reference' => $order_reference ?? ('ZIEGLER-' . time()),
            'freight_price' => $freight_price ?? null,
            'freight_currency' => 'EUR',
            'comment' => $comment_text,
        ];

        $this->createOrder($data);
    }
}
