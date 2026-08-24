<?php
// includes/functions/ph_provinces.php
// Full list of Philippine provinces (+ Metro Manila, commonly listed
// alongside provinces on PH address forms), for the Province dropdowns on
// signup, verification, and the personal-details edit page. Abilisto's
// actual service area is Surigao del Sur only (see users.municipality's
// CHECK constraint), so "Surigao del Sur" stays the default selection, but
// users can now genuinely pick any province instead of a fixed label.

function getPhilippineProvinces(): array {
    return [
        'Abra', 'Agusan del Norte', 'Agusan del Sur', 'Aklan', 'Albay',
        'Antique', 'Apayao', 'Aurora', 'Basilan', 'Bataan', 'Batanes',
        'Batangas', 'Benguet', 'Biliran', 'Bohol', 'Bukidnon', 'Bulacan',
        'Cagayan', 'Camarines Norte', 'Camarines Sur', 'Camiguin', 'Capiz',
        'Catanduanes', 'Cavite', 'Cebu', 'Cotabato', 'Davao de Oro',
        'Davao del Norte', 'Davao del Sur', 'Davao Occidental',
        'Davao Oriental', 'Dinagat Islands', 'Eastern Samar', 'Guimaras',
        'Ifugao', 'Ilocos Norte', 'Ilocos Sur', 'Iloilo', 'Isabela',
        'Kalinga', 'La Union', 'Laguna', 'Lanao del Norte', 'Lanao del Sur',
        'Leyte', 'Maguindanao del Norte', 'Maguindanao del Sur',
        'Marinduque', 'Masbate', 'Metro Manila', 'Misamis Occidental',
        'Misamis Oriental', 'Mountain Province', 'Negros Occidental',
        'Negros Oriental', 'Northern Samar', 'Nueva Ecija', 'Nueva Vizcaya',
        'Occidental Mindoro', 'Oriental Mindoro', 'Palawan', 'Pampanga',
        'Pangasinan', 'Quezon', 'Quirino', 'Rizal', 'Romblon',
        'Samar', 'Sarangani', 'Siquijor', 'Sorsogon', 'South Cotabato',
        'Southern Leyte', 'Sultan Kudarat', 'Sulu', 'Surigao del Norte',
        'Surigao del Sur', 'Tarlac', 'Tawi-Tawi', 'Zambales',
        'Zamboanga del Norte', 'Zamboanga del Sur', 'Zamboanga Sibugay',
    ];
}
