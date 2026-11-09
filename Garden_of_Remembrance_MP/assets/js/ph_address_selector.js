/**
 * Philippine Address Cascading Selector (Province -> City/Municipality -> Barangay)
 * Garden of Remembrance Memorial Park CMIS
 * 
 * Features:
 * - Proper Full Names for Provinces, Cities / Towns (Bayan / Lungsod), and Barangays
 * - Clean, Consistent, Responsive Grid Layout
 * - Pre-prioritized Pangasinan & local cities/towns with instant offline data
 * - Live PSA PSGC API integration for all Philippine regions
 * - Automatic Address Assembly & Live Preview
 * - Full backward compatibility with existing form inputs & database tables
 */
(function(window, document) {
  'use strict';

  // Master list of Philippine Provinces & Metro Manila
  var POPULAR_PROVINCES = [
    { code: "015500000", name: "Pangasinan", isPopular: true },
    { code: "130000000", name: "Metro Manila (NCR)", isRegion: true, isPopular: true },
    { code: "013300000", name: "La Union", isPopular: true },
    { code: "141100000", name: "Benguet", isPopular: true },
    { code: "036900000", name: "Tarlac", isPopular: true },
    { code: "035400000", name: "Pampanga", isPopular: true },
    { code: "037100000", name: "Zambales", isPopular: true },
    { code: "012800000", name: "Ilocos Norte", isPopular: true },
    { code: "012900000", name: "Ilocos Sur", isPopular: true },
    { code: "031400000", name: "Bulacan", isPopular: true },
    { code: "034900000", name: "Nueva Ecija", isPopular: true },
    { code: "030800000", name: "Bataan", isPopular: true },
    { code: "042100000", name: "Cavite", isPopular: true },
    { code: "043400000", name: "Laguna", isPopular: true },
    { code: "041000000", name: "Batangas", isPopular: true },
    { code: "045800000", name: "Rizal", isPopular: true },
    { code: "072200000", name: "Cebu", isPopular: true },
    { code: "112400000", name: "Davao del Sur", isPopular: true }
  ];

  var OTHER_PROVINCES = [
    { code: "140100000", name: "Abra" },
    { code: "160200000", name: "Agusan del Norte" },
    { code: "160300000", name: "Agusan del Sur" },
    { code: "060400000", name: "Aklan" },
    { code: "050500000", name: "Albay" },
    { code: "060600000", name: "Antique" },
    { code: "148100000", name: "Apayao" },
    { code: "037700000", name: "Aurora" },
    { code: "150700000", name: "Basilan" },
    { code: "020900000", name: "Batanes" },
    { code: "087800000", name: "Biliran" },
    { code: "071200000", name: "Bohol" },
    { code: "101300000", name: "Bukidnon" },
    { code: "021500000", name: "Cagayan" },
    { code: "051600000", name: "Camarines Norte" },
    { code: "051700000", name: "Camarines Sur" },
    { code: "101800000", name: "Camiguin" },
    { code: "061900000", name: "Capiz" },
    { code: "052000000", name: "Catanduanes" },
    { code: "124700000", name: "Cotabato" },
    { code: "118200000", name: "Davao de Oro" },
    { code: "112300000", name: "Davao del Norte" },
    { code: "118600000", name: "Davao Occidental" },
    { code: "112500000", name: "Davao Oriental" },
    { code: "168500000", name: "Dinagat Islands" },
    { code: "082600000", name: "Eastern Samar" },
    { code: "067900000", name: "Guimaras" },
    { code: "142700000", name: "Ifugao" },
    { code: "063000000", name: "Iloilo" },
    { code: "023100000", name: "Isabela" },
    { code: "143200000", name: "Kalinga" },
    { code: "103500000", name: "Lanao del Norte" },
    { code: "153600000", name: "Lanao del Sur" },
    { code: "083700000", name: "Leyte" },
    { code: "153800000", name: "Maguindanao del Norte" },
    { code: "153900000", name: "Maguindanao del Sur" },
    { code: "174000000", name: "Marinduque" },
    { code: "054100000", name: "Masbate" },
    { code: "104200000", name: "Misamis Occidental" },
    { code: "104300000", name: "Misamis Oriental" },
    { code: "144400000", name: "Mountain Province" },
    { code: "064500000", name: "Negros Occidental" },
    { code: "074600000", name: "Negros Oriental" },
    { code: "084800000", name: "Northern Samar" },
    { code: "025000000", name: "Nueva Vizcaya" },
    { code: "175100000", name: "Occidental Mindoro" },
    { code: "175200000", name: "Oriental Mindoro" },
    { code: "175300000", name: "Palawan" },
    { code: "045600000", name: "Quezon" },
    { code: "025700000", name: "Quirino" },
    { code: "175900000", name: "Romblon" },
    { code: "086000000", name: "Samar" },
    { code: "128000000", name: "Sarangani" },
    { code: "076100000", name: "Siquijor" },
    { code: "056200000", name: "Sorsogon" },
    { code: "126300000", name: "South Cotabato" },
    { code: "086400000", name: "Southern Leyte" },
    { code: "126500000", name: "Sultan Kudarat" },
    { code: "156600000", name: "Sulu" },
    { code: "166700000", name: "Surigao del Norte" },
    { code: "166800000", name: "Surigao del Sur" },
    { code: "157000000", name: "Tawi-Tawi" },
    { code: "097200000", name: "Zamboanga del Norte" },
    { code: "097300000", name: "Zamboanga del Sur" },
    { code: "098300000", name: "Zamboanga Sibugay" }
  ];

  // Helper to standardize and beautify city/town names
  function cleanCityName(rawName) {
    if (!rawName) return '';
    var name = String(rawName).trim();
    if (/^City of\s+(.+)$/i.test(name)) {
      return name.replace(/^City of\s+/i, '') + ' City';
    }
    if (/^Municipality of\s+(.+)$/i.test(name)) {
      return name.replace(/^Municipality of\s+/i, '');
    }
    return name;
  }

  // Comprehensive built-in offline dataset for Pangasinan and major areas
  var OFFLINE_CITIES = {
    // Pangasinan (015500000) - Full 48 cities & towns with proper names
    "015500000": [
      { code: "015503000", name: "Alaminos City", isCity: true },
      { code: "015518000", name: "Dagupan City", isCity: true },
      { code: "015538000", name: "San Carlos City", isCity: true },
      { code: "015546000", name: "Urdaneta City", isCity: true },
      { code: "015523000", name: "Lingayen (Capital)" },
      { code: "015501000", name: "Agno" },
      { code: "015502000", name: "Aguilar" },
      { code: "015504000", name: "Alcala" },
      { code: "015505000", name: "Anda" },
      { code: "015506000", name: "Asingan" },
      { code: "015507000", name: "Balungao" },
      { code: "015508000", name: "Bani" },
      { code: "015509000", name: "Basista" },
      { code: "015510000", name: "Bautista" },
      { code: "015511000", name: "Bayambang" },
      { code: "015512000", name: "Binalonan" },
      { code: "015513000", name: "Binmaley" },
      { code: "015514000", name: "Bolinao" },
      { code: "015515000", name: "Bugallon" },
      { code: "015516000", name: "Burgos" },
      { code: "015517000", name: "Calasiao" },
      { code: "015519000", name: "Dasol" },
      { code: "015520000", name: "Infanta" },
      { code: "015521000", name: "Labrador" },
      { code: "015522000", name: "Laoac" },
      { code: "015524000", name: "Mabini" },
      { code: "015525000", name: "Malasiqui" },
      { code: "015526000", name: "Manaoag" },
      { code: "015527000", name: "Mangaldan" },
      { code: "015528000", name: "Mangatarem" },
      { code: "015529000", name: "Mapandan" },
      { code: "015530000", name: "Natividad" },
      { code: "015531000", name: "Pozorrubio" },
      { code: "015532000", name: "Rosales" },
      { code: "015533000", name: "San Fabian" },
      { code: "015534000", name: "San Jacinto" },
      { code: "015535000", name: "San Manuel" },
      { code: "015536000", name: "San Nicolas" },
      { code: "015537000", name: "San Quintin" },
      { code: "015539000", name: "Santa Barbara" },
      { code: "015540000", name: "Santa Maria" },
      { code: "015541000", name: "Santo Tomas" },
      { code: "015542000", name: "Sison" },
      { code: "015543000", name: "Sual" },
      { code: "015544000", name: "Tayug" },
      { code: "015545000", name: "Umingan" },
      { code: "015547000", name: "Urbiztondo" },
      { code: "015548000", name: "Villasis" }
    ],
    // Metro Manila (130000000)
    "130000000": [
      { code: "133900000", name: "Manila City" },
      { code: "137404000", name: "Quezon City" },
      { code: "137401000", name: "Caloocan City" },
      { code: "137601000", name: "Makati City" },
      { code: "137403000", name: "Pasig City" },
      { code: "137607000", name: "Taguig City" },
      { code: "137605000", name: "Parañaque City" },
      { code: "137604000", name: "Muntinlupa City" },
      { code: "137603000", name: "Las Piñas City" },
      { code: "137602000", name: "Mandaluyong City" },
      { code: "137402000", name: "Marikina City" },
      { code: "137606000", name: "Pasay City" },
      { code: "137504000", name: "Valenzuela City" },
      { code: "137501000", name: "Malabon City" },
      { code: "137502000", name: "Navotas City" },
      { code: "137405000", name: "San Juan City" },
      { code: "137608000", name: "Pateros" }
    ],
    // La Union (013300000)
    "013300000": [
      { code: "013314000", name: "San Fernando City", isCity: true },
      { code: "013301000", name: "Agoo" },
      { code: "013304000", name: "Bauang" },
      { code: "013310000", name: "Naguilian" },
      { code: "013313000", name: "Rosario" },
      { code: "013316000", name: "San Juan" },
      { code: "013302000", name: "Aringay" },
      { code: "013303000", name: "Bacnotan" },
      { code: "013305000", name: "Bangar" },
      { code: "013307000", name: "Caba" },
      { code: "013308000", name: "Luna" },
      { code: "013317000", name: "Santo Tomas" }
    ],
    // Benguet (141100000)
    "141100000": [
      { code: "141102000", name: "Baguio City", isCity: true },
      { code: "141109000", name: "La Trinidad (Capital)" },
      { code: "141106000", name: "Itogon" },
      { code: "141113000", name: "Tuba" },
      { code: "141114000", name: "Tublay" },
      { code: "141103000", name: "Buguias" },
      { code: "141108000", name: "Mankayan" }
    ],
    // Tarlac (036900000)
    "036900000": [
      { code: "036916000", name: "Tarlac City", isCity: true },
      { code: "036905000", name: "Capas" },
      { code: "036906000", name: "Concepcion" },
      { code: "036911000", name: "Paniqui" },
      { code: "036904000", name: "Camiling" },
      { code: "036907000", name: "Gerona" },
      { code: "036902000", name: "Bamban" },
      { code: "036909000", name: "Moncada" },
      { code: "036918000", name: "Victoria" }
    ],
    // Pampanga (035400000)
    "035400000": [
      { code: "035401000", name: "Angeles City", isCity: true },
      { code: "035416000", name: "San Fernando City", isCity: true },
      { code: "035410000", name: "Mabalacat City", isCity: true },
      { code: "035408000", name: "Guagua" },
      { code: "035409000", name: "Lubao" },
      { code: "035413000", name: "Mexico" },
      { code: "035402000", name: "Arayat" }
    ]
  };

  // Comprehensive built-in barangays
  var OFFLINE_BARANGAYS = {
    // City of Alaminos (015503000) - Official barangays
    "015503000": [
      "Alos", "Amandiego", "Amangbangan", "Balangobong", "Balayang", 
      "Bisocol", "Bolaney", "Baleyadaan", "Bued", "Cabatuan", 
      "Cayucay", "Dulacac", "Inerangan", "Landoc", "Linmansangan", 
      "Lucap", "Maawi", "Macatiw", "Magsaysay", "Mona", 
      "Paitan East", "Paitan West", "Palamis", "Pandan", "Pangapisan", 
      "Poblacion", "Pocal-Pocal", "Polo", "Quibuar", "Sabangan", 
      "San Antonio", "San Jose", "San Roque", "San Vicente", "Santa Maria", 
      "Tanaytay", "Tangcarang", "Tawintawin", "Telbang", "Victoria"
    ],
    // Dagupan City (015518000)
    "015518000": [
      "Bacayao Norte", "Bacayao Sur", "Barangay I (Torres Bugallon)", "Barangay II (Nueva)",
      "Barangay IV (Zamora)", "Bolosan", "Bonuan Binloc", "Bonuan Boquig", "Bonuan Gueset",
      "Calmay", "Carael", "Caranglaan", "Herrero", "Lasip Chico", "Lasip Grande",
      "Lucao", "Malued", "Mamalingling", "Mangin", "Mayombo", "Pantalan", "Poblacion Oeste",
      "Pogo Chico", "Pogo Grande", "Pugaro Suit", "Salisay", "Tambac", "Tapuac", "Tebeng"
    ],
    // San Carlos City (015538000)
    "015538000": [
      "Abanon", "Agdao", "Ano", "Antipangol", "Balaya", "Balayong", "Baldog",
      "Balite Sur", "Bocboc", "Bogaoan", "Bolingit", "Bugallon-Posadas", "Cabalan",
      "Coliling", "Dalin", "Doyong", "Gamata", "Ilang", "Inerangan", "Libas",
      "Lilimasan", "Mabalbalino", "Mabini", "Magtaking", "Malacañang", "Maliwara",
      "Pagal", "Palaris", "Palatong", "Poblacion", "Quezon Boulevard", "Rizal",
      "Salinap", "San Juan", "Talang", "Tamayo", "Tandoc", "Tarece", "Tarectec", "Turac"
    ],
    // Urdaneta City (015546000)
    "015546000": [
      "Anonas", "Bactad East", "Bayaoas", "Cabuloan", "Camantiles", "Casantaan",
      "Catablan", "Cayambanan", "Consolacion", "Dilan-Paurido", "Labit Proper",
      "Labit West", "Mabanogbog", "Macalong", "Nancalobasaan", "Nancamaliran East",
      "Nancamaliran West", "Nancayasan", "Palina East", "Palina West", "Pinmaludpod",
      "Poblacion", "San Jose", "San Vicente", "Santa Lucia", "Santo Domingo", "Tugatug"
    ],
    // Lingayen (015523000)
    "015523000": [
      "Aliwekwek", "Baay", "Balangobong", "Balococ", "Bantayan", "Basing", "Capandanan",
      "Domalandan Center", "Domalandan East", "Domalandan West", "Dorongan", "Dulag",
      "Estanza", "Lasip", "Libsong East", "Libsong West", "Malawa", "Malimpuec",
      "Maniboc", "Matalava", "Naguelguel", "Namolan", "Pangapisan North", "Pangapisan South",
      "Poblacion", "Quibaol", "Rosario", "Sabangan", "Talogtog", "Tonton", "Tumbar", "Wawa"
    ],
    // Bani (015508000)
    "015508000": [
      "Ambabaay", "Aporao", "Araceli", "Colayo", "Dacap Norte", "Dacap Sur",
      "Garrita", "Luac", "Macabit", "Masidem", "Poblacion", "Rano", "San Jose",
      "San Miguel", "San Simon", "San Vicente", "Tiep", "Tipor", "Tugui Grande", "Tugui Norte"
    ],
    // Bolinao (015514000)
    "015514000": [
      "Arnedo", "Balingasay", "Binabalian", "Cabuyao", "Catuday", "Concordia",
      "Culala", "Dewey", "Estanza", "Germinal (Poblacion)", "Goyoden", "Ilogmalino",
      "Lambabes", "Liwa-liwa", "Lucero", "Luciente 1st", "Luciente 2nd", "Patar",
      "Pilar", "Salud", "Samang Norte", "Samang Sur", "Sampaguita", "San Roque",
      "Tara", "Tondol", "Victory", "Zaragoza"
    ],
    // Sual (015543000)
    "015543000": [
      "Baquioen", "Baybay Norte", "Baybay Sur", "Bolaoen", "Cabiangaan", "Calumboyan",
      "Campos", "Caoayan", "Capantolan", "Macaboboni", "Paitan East", "Paitan West", "Pangapisan",
      "Poblacion", "Santo Domingo", "Seselangen", "Sioasio East", "Sioasio West", "Victoria"
    ],
    // Mabini (015524000)
    "015524000": [
      "Bacnit", "Barlo", "Caabiangaan", "Cabanaetan", "Cabungaoan", "Calzada",
      "Caranglaan", "De Guzman", "Luna", "Magalong", "Nibaliw", "Patar", "Poblacion",
      "San Pedro", "Tagudin", "Villacorta"
    ],
    // Bugallon (015515000)
    "015515000": [
      "Angarian", "Asinan", "Bacabac", "Bolaoen", "Buenlag", "Cabayaoasan", "Cayanga",
      "Gueset", "Hacienda", "Laguit Centro", "Laguit Padilla", "Magtaking", "Pangon",
      "Poblacion", "Polong", "Portic", "Salasa", "San Francisco", "Samput", "Tanolong"
    ],
    // Calasiao (015517000)
    "015517000": [
      "Ambuetel", "Banaoang", "Buenlag", "Cabilocaan", "Dinalaoan", "Doyong", "Gabon",
      "Lasip", "Longos", "Lumbang", "Macabito", "Malabago", "Mancup", "Nalsian",
      "Poblacion East", "Poblacion West", "Quesban", "San Miguel", "Talibaew"
    ],
    // Baguio City (141102000)
    "141102000": [
      "Abanao-Zandueta-Kayong-Chugum-Otek (AZKCO)", "Asin Road", "Aurora Hill",
      "Bakakeng Central", "Bakakeng North", "Cabinet Hill-Teacher's Camp", "Camp 7",
      "Camp 8", "Country Club Village", "DPS Compound", "Engineers' Hill", "General Luna",
      "Gibraltar", "Irisan", "Kias", "Loakan", "Lualhati", "Mines View Park",
      "Pacdal", "Pinsao Proper", "Quirino Hill", "Session Road Area", "Trancoville"
    ]
  };

  // In-memory cache for API results
  var cache = {
    cities: {},
    barangays: {}
  };

  function fetchJson(url) {
    return fetch(url).then(function(res) {
      if (!res.ok) throw new Error("HTTP error " + res.status);
      return res.json();
    });
  }

  function getCities(provinceCode, isRegion) {
    if (cache.cities[provinceCode]) {
      return Promise.resolve(cache.cities[provinceCode]);
    }
    if (OFFLINE_CITIES[provinceCode] && OFFLINE_CITIES[provinceCode].length > 0) {
      cache.cities[provinceCode] = OFFLINE_CITIES[provinceCode];
      return Promise.resolve(OFFLINE_CITIES[provinceCode]);
    }

    var apiUrl = isRegion
      ? "https://psgc.gitlab.io/api/regions/" + provinceCode + "/cities-municipalities.json"
      : "https://psgc.gitlab.io/api/provinces/" + provinceCode + "/cities-municipalities.json";

    return fetchJson(apiUrl)
      .then(function(data) {
        var list = data.map(function(item) {
          return {
            code: item.code,
            name: cleanCityName(item.name),
            rawName: item.name,
            isCity: item.isCity
          };
        });
        list.sort(function(a, b) { return a.name.localeCompare(b.name); });
        cache.cities[provinceCode] = list;
        return list;
      })
      .catch(function(err) {
        console.warn("Using offline fallback for province:", provinceCode, err);
        return OFFLINE_CITIES[provinceCode] || [];
      });
  }

  function getBarangays(cityCode) {
    if (cache.barangays[cityCode]) {
      return Promise.resolve(cache.barangays[cityCode]);
    }
    if (OFFLINE_BARANGAYS[cityCode] && OFFLINE_BARANGAYS[cityCode].length > 0) {
      cache.barangays[cityCode] = OFFLINE_BARANGAYS[cityCode];
      return Promise.resolve(OFFLINE_BARANGAYS[cityCode]);
    }

    var apiUrl = "https://psgc.gitlab.io/api/cities-municipalities/" + cityCode + "/barangays.json";

    return fetchJson(apiUrl)
      .then(function(data) {
        var list = data.map(function(item) {
          return item.name.trim();
        });
        list.sort(function(a, b) { return a.localeCompare(b); });
        cache.barangays[cityCode] = list;
        return list;
      })
      .catch(function(err) {
        console.warn("Using offline fallback for barangays:", cityCode, err);
        return OFFLINE_BARANGAYS[cityCode] || [];
      });
  }

  /**
   * Component Constructor
   */
  function PhAddressSelector(targetEl, options) {
    if (!targetEl) return;
    this.target = targetEl;
    this.options = Object.assign({
      defaultProvince: "Pangasinan",
      defaultCity: "Alaminos City",
      required: true
    }, options || {});

    this.init();
  }

  PhAddressSelector.prototype.init = function() {
    var self = this;
    var target = this.target;

    var origId = target.id || ('ph_addr_' + Math.random().toString(36).substr(2, 9));
    var origValue = target.value ? target.value.trim() : '';

    // If an outer label exists directly preceding the input, we hide it to prevent duplicate labels
    var prev = target.previousElementSibling;
    if (prev && prev.tagName === 'LABEL') {
      prev.style.display = 'none';
    }

    // Component Container
    var wrapper = document.createElement('div');
    wrapper.className = 'ph-address-component card border shadow-none p-3 mb-2 bg-light bg-opacity-50';
    wrapper.style.cssText = 'border-radius: 12px; border: 1.5px solid #e2e8f0 !important; background: #fbfcfd;';
    wrapper.setAttribute('data-target-id', origId);

    wrapper.innerHTML = [
      '<div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom border-light-subtle flex-wrap gap-2">',
      '  <div class="d-flex align-items-center gap-2">',
      '    <div style="width: 28px; height: 28px; border-radius: 6px; background: rgba(123, 17, 19, 0.1); display: flex; align-items: center; justify-content: center;">',
      '      <i class="fas fa-map-marked-alt text-danger" style="font-size: 0.85rem;"></i>',
      '    </div>',
      '    <div>',
      '      <span class="fw-bold text-dark small d-block" style="font-size: 0.85rem;">Address &amp; Location Details</span>',
      '      <span class="text-muted" style="font-size: 0.7rem;">Select Province, City / Municipality, and Barangay</span>',
      '    </div>',
      '  </div>',
      '  <div>',
      '    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 ph-toggle-mode" style="font-size: 0.72rem; border-radius: 20px; font-weight: 500;">',
      '      <i class="fas fa-keyboard me-1"></i> <span class="ph-toggle-text">Type address manually</span>',
      '    </button>',
      '  </div>',
      '</div>',
      '<div class="ph-dropdown-group">',
      '  <div class="row g-2">',
      '    <div class="col-12 col-md-4">',
      '      <label class="form-label small fw-semibold text-secondary mb-1" style="font-size: 0.78rem;">',
      '        <i class="fas fa-map text-danger me-1"></i> 1. Province / Region <span class="text-danger">*</span>',
      '      </label>',
      '      <select class="form-select form-select-sm ph-sel-province" style="border-radius: 8px; font-size: 0.84rem; padding: 0.45rem 0.65rem;" ' + (this.options.required ? 'required' : '') + '>',
      '        <option value="">-- Select Province --</option>',
      '      </select>',
      '    </div>',
      '    <div class="col-12 col-md-4">',
      '      <label class="form-label small fw-semibold text-secondary mb-1" style="font-size: 0.78rem;">',
      '        <i class="fas fa-city text-primary me-1"></i> 2. City / Municipality <span class="text-danger">*</span>',
      '      </label>',
      '      <select class="form-select form-select-sm ph-sel-city" disabled style="border-radius: 8px; font-size: 0.84rem; padding: 0.45rem 0.65rem;" ' + (this.options.required ? 'required' : '') + '>',
      '        <option value="">-- Select City / Municipality --</option>',
      '      </select>',
      '    </div>',
      '    <div class="col-12 col-md-4">',
      '      <label class="form-label small fw-semibold text-secondary mb-1" style="font-size: 0.78rem;">',
      '        <i class="fas fa-map-pin text-success me-1"></i> 3. Barangay <span class="text-danger">*</span>',
      '      </label>',
      '      <select class="form-select form-select-sm ph-sel-barangay" disabled style="border-radius: 8px; font-size: 0.84rem; padding: 0.45rem 0.65rem;" ' + (this.options.required ? 'required' : '') + '>',
      '        <option value="">-- Select Barangay --</option>',
      '      </select>',
      '    </div>',
      '    <div class="col-12 mt-2">',
      '      <label class="form-label small fw-semibold text-secondary mb-1" style="font-size: 0.78rem;">',
      '        <i class="fas fa-road text-muted me-1"></i> Street / House No., Building, Subdivision / Sitio <span class="text-muted fw-normal">(Optional)</span>',
      '      </label>',
      '      <input type="text" class="form-control form-control-sm ph-input-street" placeholder="e.g. House #123, Rizal Street, Greenfields Subd." style="border-radius: 8px; font-size: 0.84rem; padding: 0.45rem 0.75rem;">',
      '    </div>',
      '  </div>',
      '</div>',
      // Manual input container (hidden by default)
      '<div class="ph-manual-group" style="display:none;">',
      '  <label class="form-label small fw-semibold text-secondary mb-1" style="font-size: 0.78rem;">',
      '    <i class="fas fa-pencil-alt text-warning me-1"></i> Complete Residential / Walk-in Address',
      '  </label>',
      '  <input type="text" class="form-control form-control-sm ph-input-manual" placeholder="e.g. 123 Rizal St., Brgy. Poblacion, Alaminos City, Pangasinan" style="border-radius: 8px; font-size: 0.84rem; padding: 0.45rem 0.75rem;">',
      '  <div class="form-text text-muted" style="font-size: 0.7rem;">Type the full address if located outside the Philippines or for custom format.</div>',
      '</div>',
      // Live Selected Address Preview
      '<div class="ph-addr-preview mt-2 p-2 rounded bg-white border border-light-subtle d-flex align-items-center gap-2" style="' + (origValue ? '' : 'display:none;') + '">',
      '  <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 flex-shrink-0" style="font-size: 0.72rem; font-weight: 600;">',
      '    <i class="fas fa-check-circle me-1"></i> Selected Address',
      '  </span>',
      '  <span class="ph-preview-text fw-semibold text-dark text-truncate" style="font-size: 0.82rem;">' + (origValue || 'No location selected') + '</span>',
      '</div>'
    ].join('\n');

    // Attach wrapper before target input and hide target
    target.parentNode.insertBefore(wrapper, target);
    target.style.display = 'none';
    wrapper.appendChild(target);

    // Grab elements
    var selProvince = wrapper.querySelector('.ph-sel-province');
    var selCity = wrapper.querySelector('.ph-sel-city');
    var selBarangay = wrapper.querySelector('.ph-sel-barangay');
    var inputStreet = wrapper.querySelector('.ph-input-street');
    var inputManual = wrapper.querySelector('.ph-input-manual');
    var manualGroup = wrapper.querySelector('.ph-manual-group');
    var dropdownGroup = wrapper.querySelector('.ph-dropdown-group');
    var previewContainer = wrapper.querySelector('.ph-addr-preview');
    var previewText = wrapper.querySelector('.ph-preview-text');
    var toggleBtn = wrapper.querySelector('.ph-toggle-mode');
    var toggleText = wrapper.querySelector('.ph-toggle-text');

    this.elements = {
      wrapper: wrapper,
      selProvince: selProvince,
      selCity: selCity,
      selBarangay: selBarangay,
      inputStreet: inputStreet,
      inputManual: inputManual,
      manualGroup: manualGroup,
      dropdownGroup: dropdownGroup,
      previewContainer: previewContainer,
      previewText: previewText,
      toggleBtn: toggleBtn,
      toggleText: toggleText,
      target: target
    };

    var isManualMode = false;

    // Populate Provinces with Optgroups for clean hierarchy
    var optgroupPopular = document.createElement('optgroup');
    optgroupPopular.label = "Popular / Local Provinces";
    POPULAR_PROVINCES.forEach(function(prov) {
      var opt = document.createElement('option');
      opt.value = prov.code;
      opt.textContent = prov.name;
      opt.setAttribute('data-name', prov.name);
      if (prov.isRegion) opt.setAttribute('data-is-region', '1');
      optgroupPopular.appendChild(opt);
    });
    selProvince.appendChild(optgroupPopular);

    var optgroupOther = document.createElement('optgroup');
    optgroupOther.label = "All Other Philippine Provinces (A-Z)";
    OTHER_PROVINCES.forEach(function(prov) {
      var opt = document.createElement('option');
      opt.value = prov.code;
      opt.textContent = prov.name;
      opt.setAttribute('data-name', prov.name);
      optgroupOther.appendChild(opt);
    });
    selProvince.appendChild(optgroupOther);

    // Sync selected address to original target input
    function updateAddress() {
      if (isManualMode) {
        var manVal = inputManual.value.trim();
        self._isInternalUpdating = true;
        target.value = manVal;
        self._isInternalUpdating = false;
        previewText.textContent = manVal || 'No location selected';
        previewContainer.style.display = manVal ? 'flex' : 'none';
        triggerEvent(target, 'input');
        triggerEvent(target, 'change');
        return;
      }

      var provOpt = selProvince.options[selProvince.selectedIndex];
      var provName = provOpt && provOpt.value ? provOpt.getAttribute('data-name') : '';

      var cityOpt = selCity.options[selCity.selectedIndex];
      var cityName = cityOpt && cityOpt.value ? cityOpt.getAttribute('data-name') : '';

      var brgyVal = selBarangay.value ? selBarangay.value.trim() : '';
      var streetVal = inputStreet ? inputStreet.value.trim() : '';

      var parts = [];
      if (streetVal) parts.push(streetVal);
      if (brgyVal && brgyVal !== "Other / Unlisted Barangay") {
        var brgyPrefix = (brgyVal.toLowerCase().indexOf('brgy') !== -1 || brgyVal.toLowerCase().indexOf('barangay') !== -1)
          ? brgyVal
          : ('Brgy. ' + brgyVal);
        parts.push(brgyPrefix);
      }
      if (cityName) {
        parts.push(cityName);
      }
      if (provName && provName !== cityName) {
        parts.push(provName);
      }

      var fullAddress = parts.join(', ');
      self._isInternalUpdating = true;
      target.value = fullAddress;
      self._isInternalUpdating = false;

      previewText.textContent = fullAddress || 'No location selected';
      previewContainer.style.display = fullAddress ? 'flex' : 'none';

      triggerEvent(target, 'input');
      triggerEvent(target, 'change');
    }

    function triggerEvent(el, evtName) {
      var event = new Event(evtName, { bubbles: true, cancelable: true });
      el.dispatchEvent(event);
    }

    // Toggle Manual Mode
    if (toggleBtn) {
      toggleBtn.addEventListener('click', function() {
        isManualMode = !isManualMode;
        if (isManualMode) {
          dropdownGroup.style.display = 'none';
          manualGroup.style.display = 'block';
          toggleText.textContent = 'Use Location Dropdowns';
          inputManual.value = target.value;
          inputManual.focus();
        } else {
          dropdownGroup.style.display = 'block';
          manualGroup.style.display = 'none';
          toggleText.textContent = 'Type address manually';
          updateAddress();
        }
      });
    }

    if (inputManual) {
      inputManual.addEventListener('input', updateAddress);
    }
    if (inputStreet) {
      inputStreet.addEventListener('input', updateAddress);
    }

    // Province Selection Changed -> Populate Cities / Towns
    selProvince.addEventListener('change', function() {
      var pCode = this.value;
      selCity.innerHTML = '<option value="">-- Select City / Municipality --</option>';
      selBarangay.innerHTML = '<option value="">-- Select Barangay --</option>';
      selCity.disabled = true;
      selBarangay.disabled = true;

      if (!pCode) {
        updateAddress();
        return;
      }

      var isReg = this.options[this.selectedIndex].getAttribute('data-is-region') === '1';
      selCity.innerHTML = '<option value="">Loading cities/towns...</option>';

      getCities(pCode, isReg).then(function(cities) {
        selCity.innerHTML = '<option value="">-- Select City / Municipality --</option>';
        if (cities && cities.length > 0) {
          cities.forEach(function(c) {
            var opt = document.createElement('option');
            opt.value = c.code;
            opt.textContent = c.name;
            opt.setAttribute('data-name', c.name);
            selCity.appendChild(opt);
          });
          selCity.disabled = false;
        } else {
          selCity.innerHTML = '<option value="">No cities or municipalities found</option>';
        }
        updateAddress();
      }).catch(function() {
        selCity.innerHTML = '<option value="">Error loading cities</option>';
        updateAddress();
      });
    });

    // City Selection Changed -> Populate Barangays
    selCity.addEventListener('change', function() {
      var cCode = this.value;
      selBarangay.innerHTML = '<option value="">-- Select Barangay --</option>';
      selBarangay.disabled = true;

      if (!cCode) {
        updateAddress();
        return;
      }

      selBarangay.innerHTML = '<option value="">Loading barangays...</option>';

      getBarangays(cCode).then(function(brgys) {
        selBarangay.innerHTML = '<option value="">-- Select Barangay --</option>';
        if (brgys && brgys.length > 0) {
          brgys.forEach(function(b) {
            var opt = document.createElement('option');
            opt.value = b;
            opt.textContent = b;
            selBarangay.appendChild(opt);
          });
          // Unlisted / Custom Barangay Option
          var otherOpt = document.createElement('option');
          otherOpt.value = "Other / Unlisted Barangay";
          otherOpt.textContent = "— Other / Unlisted Barangay (Type Custom) —";
          selBarangay.appendChild(otherOpt);
          selBarangay.disabled = false;
        } else {
          selBarangay.innerHTML = '<option value="">No barangays found</option>';
        }
        updateAddress();
      }).catch(function() {
        selBarangay.innerHTML = '<option value="">Error loading barangays</option>';
        updateAddress();
      });
    });

    // Barangay Selection Changed
    selBarangay.addEventListener('change', function() {
      if (this.value === "Other / Unlisted Barangay") {
        var custom = prompt("Please enter Barangay, Purok, or Sitio name:");
        if (custom && custom.trim()) {
          var opt = document.createElement('option');
          opt.value = custom.trim();
          opt.textContent = custom.trim();
          opt.selected = true;
          selBarangay.appendChild(opt);
        } else {
          selBarangay.selectedIndex = 0;
        }
      }
      updateAddress();
    });

    // Hook property setter so external scripts (e.g. setBookingFieldValue) sync the UI automatically
    try {
      var protoValDesc = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
      if (protoValDesc && protoValDesc.set && protoValDesc.get) {
        Object.defineProperty(target, 'value', {
          get: function() {
            return protoValDesc.get.call(this);
          },
          set: function(val) {
            protoValDesc.set.call(this, val);
            if (!self._isInternalUpdating) {
              self.setValue(val);
            }
          },
          configurable: true
        });
      }
    } catch(e) {}

    // Pre-populate if target already has an address, otherwise set smart default to Pangasinan -> Alaminos City
    if (origValue) {
      this.setValue(origValue);
    } else if (this.options.defaultProvince) {
      this.setDefaultLocation(this.options.defaultProvince, this.options.defaultCity);
    }
  };

  /**
   * Set default province and city (e.g. Pangasinan -> Alaminos City)
   */
  PhAddressSelector.prototype.setDefaultLocation = function(provName, cityName) {
    var self = this;
    var selProv = this.elements.selProvince;
    var selCity = this.elements.selCity;

    for (var i = 0; i < selProv.options.length; i++) {
      if (selProv.options[i].getAttribute('data-name') === provName || selProv.options[i].textContent.indexOf(provName) !== -1) {
        selProv.selectedIndex = i;
        var pCode = selProv.value;
        var isReg = selProv.options[i].getAttribute('data-is-region') === '1';

        getCities(pCode, isReg).then(function(cities) {
          selCity.innerHTML = '<option value="">-- Select City / Municipality --</option>';
          var matchedIdx = -1;
          cities.forEach(function(c, idx) {
            var opt = document.createElement('option');
            opt.value = c.code;
            opt.textContent = c.name;
            opt.setAttribute('data-name', c.name);
            selCity.appendChild(opt);

            var cleanName = cleanCityName(c.name).toLowerCase();
            var targetClean = cleanCityName(cityName || '').toLowerCase();
            if (cityName && (cleanName === targetClean || cleanName.indexOf(targetClean) !== -1 || targetClean.indexOf(cleanName) !== -1)) {
              matchedIdx = idx + 1;
            }
          });

          selCity.disabled = false;
          if (matchedIdx > 0) {
            selCity.selectedIndex = matchedIdx;
            var cCode = selCity.value;
            getBarangays(cCode).then(function(brgys) {
              var selBrgy = self.elements.selBarangay;
              selBrgy.innerHTML = '<option value="">-- Select Barangay --</option>';
              brgys.forEach(function(b) {
                var opt = document.createElement('option');
                opt.value = b;
                opt.textContent = b;
                selBrgy.appendChild(opt);
              });
              var otherOpt = document.createElement('option');
              otherOpt.value = "Other / Unlisted Barangay";
              otherOpt.textContent = "— Other / Unlisted Barangay (Type Custom) —";
              selBrgy.appendChild(otherOpt);
              selBrgy.disabled = false;
            });
          }
        });
        break;
      }
    }
  };

  /**
   * Set address programmatically from full text string
   */
  PhAddressSelector.prototype.setValue = function(fullAddress) {
    if (!fullAddress) return;
    var self = this;
    var trimmed = fullAddress.trim();
    self._isInternalUpdating = true;
    this.elements.target.value = trimmed;
    self._isInternalUpdating = false;

    this.elements.inputManual.value = trimmed;
    this.elements.previewText.textContent = trimmed;
    this.elements.previewContainer.style.display = 'flex';

    // Try to match Province
    var allProvs = POPULAR_PROVINCES.concat(OTHER_PROVINCES);
    var matchedProv = null;
    for (var i = 0; i < allProvs.length; i++) {
      var p = allProvs[i];
      var rx = new RegExp('\\b' + p.name.replace(/[-/\\^$*+?.()|[\]{}]/g, '\\$&') + '\\b', 'i');
      if (rx.test(trimmed)) {
        matchedProv = p;
        break;
      }
    }

    if (matchedProv) {
      var selProv = this.elements.selProvince;
      selProv.value = matchedProv.code;

      getCities(matchedProv.code, matchedProv.isRegion).then(function(cities) {
        var selCity = self.elements.selCity;
        selCity.innerHTML = '<option value="">-- Select City / Municipality --</option>';
        var matchedCity = null;

        cities.forEach(function(c) {
          var opt = document.createElement('option');
          opt.value = c.code;
          opt.textContent = c.name;
          opt.setAttribute('data-name', c.name);
          selCity.appendChild(opt);

          var cClean = cleanCityName(c.name).replace(/\s+(City|Capital)$/i, '').trim();
          var rxCity = new RegExp('\\b' + cClean.replace(/[-/\\^$*+?.()|[\]{}]/g, '\\$&') + '\\b', 'i');
          if (rxCity.test(trimmed)) {
            matchedCity = c;
          }
        });

        selCity.disabled = false;
        if (matchedCity) {
          selCity.value = matchedCity.code;
          getBarangays(matchedCity.code).then(function(brgys) {
            var selBrgy = self.elements.selBarangay;
            selBrgy.innerHTML = '<option value="">-- Select Barangay --</option>';
            var matchedBrgy = null;

            brgys.forEach(function(b) {
              var opt = document.createElement('option');
              opt.value = b;
              opt.textContent = b;
              selBrgy.appendChild(opt);

              var rxBrgy = new RegExp('\\b' + b.replace(/[-/\\^$*+?.()|[\]{}]/g, '\\$&') + '\\b', 'i');
              if (rxBrgy.test(trimmed)) {
                matchedBrgy = b;
              }
            });

            var otherOpt = document.createElement('option');
            otherOpt.value = "Other / Unlisted Barangay";
            otherOpt.textContent = "— Other / Unlisted Barangay (Type Custom) —";
            selBrgy.appendChild(otherOpt);

            selBrgy.disabled = false;
            if (matchedBrgy) {
              selBrgy.value = matchedBrgy;
            }
          });
        }
      });
    }
  };

  /**
   * Helper function to attach to any input selector
   */
  window.initPhAddressSelector = function(targetInputOrId, options) {
    var el = typeof targetInputOrId === 'string' ? document.getElementById(targetInputOrId) : targetInputOrId;
    if (!el) {
      return null;
    }
    if (el._phAddressSelector) {
      return el._phAddressSelector;
    }
    var instance = new PhAddressSelector(el, options);
    el._phAddressSelector = instance;
    return instance;
  };

  /**
   * Auto-initialize elements with attribute data-ph-address-selector="true"
   */
  document.addEventListener('DOMContentLoaded', function() {
    var autoElements = document.querySelectorAll('[data-ph-address-selector="true"]');
    autoElements.forEach(function(el) {
      window.initPhAddressSelector(el);
    });
  });

})(window, document);
