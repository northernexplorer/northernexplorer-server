<?php
if (count(get_included_files()) === 1) {
    http_response_code(403);
    echo json_encode(["error" => "Direct access denied."]);
    exit();
}

function getLunarData() {
    // Known reference New Moon date (January 6, 2000, 18:14 UTC)
    $referenceTime = gmmktime(18, 14, 0, 1, 6, 2000);
    $currentTime = time(); // Current server timestamp

    // Length of a single lunar synodic month in seconds
    $lunarPeriodSeconds = 29.530588853 * 86400;

    // Total seconds elapsed since the reference point
    $elapsedSeconds = $currentTime - $referenceTime;

    // Calculate current position in the current cycle (0.0 to 1.0)
    $currentPhaseFraction = fmod($elapsedSeconds, $lunarPeriodSeconds) / $lunarPeriodSeconds;
    if ($currentPhaseFraction < 0) {
        $currentPhaseFraction += 1.0;
    }

    // Convert fraction into age of the moon in days (0 to 29.53)
    $moonAgeDays = $currentPhaseFraction * 29.530588853;

    // Determine Phase Name and Illumination Percentage
    // Illumination is 0% at New Moon, 100% at Full Moon, and 0% again at the next New Moon
    if ($currentPhaseFraction < 0.5) {
        $illumination = $currentPhaseFraction * 2; // Growing towards Full Moon
    } else {
        $illumination = (1.0 - $currentPhaseFraction) * 2; // Shrinking towards New Moon
    }

    // Determine structural name layout based on standard octants
    if ($moonAgeDays < 1) {
        $phaseName = "New Moon";
    } elseif ($moonAgeDays < 6.38) {
        $phaseName = "Waxing Crescent";
    } elseif ($moonAgeDays < 8.38) {
        $phaseName = "First Quarter";
    } elseif ($moonAgeDays < 13.76) {
        $phaseName = "Waxing Gibbous";
    } elseif ($moonAgeDays < 15.76) {
        $phaseName = "Full Moon";
    } elseif ($moonAgeDays < 21.14) {
        $phaseName = "Waning Gibbous";
    } elseif ($moonAgeDays < 23.14) {
        $phaseName = "Third Quarter";
    } elseif ($moonAgeDays < 28.53) {
        $phaseName = "Waning Crescent";
    } else {
        $phaseName = "New Moon";
    }

    return [
        "phase_fraction" => round($currentPhaseFraction, 4),
        "moon_age_days" => round($moonAgeDays, 2),
        "illumination_percentage" => round($illumination * 100, 1),
        "phase_name" => $phaseName,
        "is_waxing" => ($currentPhaseFraction < 0.5)
    ];
}