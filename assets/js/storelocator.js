//import settings from '../json/settings/settings.json' assert { type: "json" };

//const { default: settings } = await import("../json/settings/settings.json", { assert: { type: "json" } })

// import settings from '../json/settings/settings.json';

// if (typeof settings !== 'object' || Array.isArray(settings)) {
//     throw new Error('Le fichier JSON ne contient pas un objet JSON valide.');
// }

// Use the plugin path passed from PHP, fallback to default if not available
var pluginDirPath = (typeof storelocator_vars !== 'undefined') ? storelocator_vars.plugin_url : '/wp-content/plugins/novi_storelocator/';

const localizedSettings = (typeof storelocator_vars !== 'undefined' && storelocator_vars.settings && typeof storelocator_vars.settings === 'object')
    ? storelocator_vars.settings
    : {};

let settings = {
    apikey: '',
    btncolor: '',
    btncolorbg: '',
    ...localizedSettings
};

//console.log(pluginDirPath)
// pluginDirPath is defined in novi_storelocator.php
// pluginDirPath is a php var

const searchInput = document.getElementById('searchInput');
const searchResults = document.getElementById('searchResults');
const loadingMessage = document.getElementById('loadingMessage');
const listInfo = document.getElementById('listinfo');
const mapHTML = document.getElementById('map');
const hasStoreLocatorDom = Boolean(searchInput && searchResults && loadingMessage && listInfo && mapHTML);
let timeoutId;
var marker = [];
var searchradius = 10; //km
var searchradiuslist =        [10,20,30,50,75,100,125,150,200,250,300,400,500,1000];
var searchradiuslistzoommap = [11,10,10, 9, 8,  8,  8,  7,  7,  7,  6,  6,  5,   5];
var positionAccepted = false;
let userLatitude;
let userLongitude;
let useCricle = false;
let howManyResults = 4;

let json_mag_path = pluginDirPath+"assets/json/stores.json";
let json_communes_path = pluginDirPath+"assets/json/communes.json";
let custom_position_icon_url = pluginDirPath+'assets/img/curpos2.png';

let storesDataPromise = null;

function loadStoresData() {
    if (!storesDataPromise) {
        storesDataPromise = fetch(json_mag_path)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Erreur lors du chargement du fichier');
                }
                return response.json();
            })
            .catch(error => {
                storesDataPromise = null;
                throw error;
            });
    }

    return storesDataPromise;
}

let key = settings['apikey']
let btncolor = settings['btncolor']
let btncolorbg = settings['btncolorbg']

if (listInfo) {
    listInfo.style.display = "none";
}

let map; // Declare the map variable globally
let customIcon = null;
let signatureIcon = null;

function initializeMap() {
    if (map || !mapHTML || typeof L === 'undefined') {
        // If the map is already initialized, do nothing
        return;
    }

    // Initialize the map only if it hasn't been initialized yet
    map = L.map('map', {
        center: [46.614985, 2.463600],
        attribution: '© MapTiler', // Attribution
        zoom: 6
    });

    var osmBase = L.tileLayer(`https://api.maptiler.com/maps/basic-v2/{z}/{x}/{y}.png?key=${key}`);
    osmBase.addTo(map);
}

// Call initializeMap where the map is being created
if (hasStoreLocatorDom && typeof L !== 'undefined') {
    initializeMap();

    if(useCricle){

        var circle = L.circle([0, 0], {
                                color: 'blue',
                                fillColor: '#4287f5',
                                fillOpacity: 0.5,
                                radius: 5000 // 5 km en mètres
                            }).addTo(map);

        map.removeLayer(circle);
        circle = null;

    }

    // ------------------ CUSTOM ICON POS -----------------------

    customIcon = L.icon({
        iconUrl: custom_position_icon_url, // URL de l'icône personnalisée
        iconSize: [38, 38], // Taille de l'icône [largeur, hauteur]
        iconAnchor: [19, 38], // Point d'ancrage de l'icône
        popupAnchor: [0, -38] // Point d'ancrage du popup
    });

    // ------------------ USER GEO ------------------------------

    if ('geolocation' in navigator) {
    navigator.geolocation.getCurrentPosition(
        position => {

            positionAccepted = true;
            //console.log(positionAccepted)

            const { latitude, longitude } = position.coords;
            userLatitude = latitude;
            userLongitude = longitude;

            // Placer un marqueur sur la carte à l'emplacement de la position actuelle
            L.marker([latitude, longitude], { icon: customIcon }).addTo(map)
                .bindPopup('Vous êtes ici !')
                .openPopup();

            // Centrer la carte sur la position actuelle
            map.setView([latitude, longitude], 13);
            var result_geo = findNearbyStoresRadiuses(latitude, longitude, searchradiuslist, howManyResults)
            .then(result_geo => {

                const nearbyStores = result_geo.stores
                const indexes = result_geo.indexes
                const rad = result_geo.radius
                const zoom = result_geo.zoom

                //console.log(nearbyStores)
                //console.log(indexes)

                if(nearbyStores.length > 0 || indexes.length > 0){
                    //console.log("res1");
                    //console.log(indexes);
                    //listInfo.innerHTML += "<div class='listinfo-title' id='listinfo-title'>Voici les 4 magasins les plus proches de vous :</div><div class='listinfo-subtitle'>Rayon : "+rad+" km</div>";
                    generate_mag_list("", "", indexes, nearbyStores);
                    if (listInfo) {
                        listInfo.style.display = "flex";
                    }
                }
                map.setView([latitude, longitude], zoom);

                if(useCricle){
                    circle = L.circle([latitude, longitude], {
                            color: 'blue',
                            fillColor: '#4287f5',
                            fillOpacity: 0.01,
                            radius: (rad*1000) // 5 km en mètres
                        }).addTo(map);
                }

                })
        },
        error => {
            console.error('Erreur de géolocalisation : ', error);
        }
    );
    } else {
    console.log('La géolocalisation n\'est pas prise en charge par votre navigateur.');
    }

    const startStoreLocatorBootstrap = () => {
        loadStoresData()
            .then(data => {
                for(let i = 0; i < data.length; i++) {
                    let obj = data[i];

                    if(obj.active == "1"){

                        if(obj.address2){
                            var adress2texttip = "<b>"+obj.address2+"</b>";
                        }else{
                            var adress2texttip = "";
                        }
                        
                        if('icone' in obj && obj.icone === 'signature'){
                            marker[i] = L.marker([obj.latitude,obj.longitude], {icon: signatureIcon}).addTo(map);
                            var signature = "<b style='color: #bd7639'>soins en institut</b>";
                        }else{
                            marker[i] = L.marker([obj.latitude,obj.longitude]).addTo(map);
                            var signature = "";
                        }

                        marker[i].bindPopup("<div class='popup-marker'><div class='popup-info'><b>"+obj.name+"</b><b>"+obj.address1+", "+obj.postcode+" "+obj.city+"</b>"+adress2texttip + signature + "<a class='sl-btn' style='color:"+btncolor+"; background-color:"+btncolorbg+";' onclick='event.stopPropagation(); ouvrirTrajetGoogleMapsCoordonnees("+obj.latitude+","+obj.longitude+")'>J'Y VAIS</a></div></div>");
                    }
                }
            })
            .catch(error => {
                console.error('Impossible de charger les magasins :', error);
            });
    };

    if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(startStoreLocatorBootstrap, { timeout: 1500 });
    } else {
        window.setTimeout(startStoreLocatorBootstrap, 0);
    }
}

// ------------------ TRAJET --------------------------------

export function ouvrirTrajetGoogleMapsCoordonnees(arriveeLat, arriveeLong) {
    const baseUrl = 'https://www.google.com/maps/dir/?api=1';
    var url;
        if(positionAccepted){
            url = `${baseUrl}&origin=${userLatitude},${userLongitude}&destination=${arriveeLat},${arriveeLong}`;
        }else{
            url = `${baseUrl}&destination=${arriveeLat},${arriveeLong}`;
        }
        window.open(url);
        //console.log(url)
}

if (typeof window !== 'undefined') {
    window.ouvrirTrajetGoogleMapsCoordonnees = ouvrirTrajetGoogleMapsCoordonnees;
}


// ------------------ USER GEO ------------------------------
const LeafIcon = (typeof L !== 'undefined') ? L.Icon.extend({
    options: {
        shadowUrl: 'https://unpkg.com/browse/leaflet@1.9.4/dist/images/marker-shadow.png'
    }
    }) : null;

signatureIcon = (LeafIcon) ? new LeafIcon({iconUrl: '/wp-content/plugins/novi_storelocator/assets/img/signature.png'}) : null;




export function marker_localize(marker_id){
    if (!map || !marker[marker_id]) {
        return;
    }
    var thismarker = marker[marker_id];
            thismarker.openPopup();
            //map.panTo(new L.LatLng(obj.lat, obj.lon));
            map.setView(thismarker.getLatLng(), 12);
}

if (typeof window !== 'undefined') {
    window.marker_localize = marker_localize;
}

document.addEventListener('DOMContentLoaded', () => {
    if (!hasStoreLocatorDom) {
        return;
    }

    searchInput.addEventListener('input', function() {
        const searchTerm = searchInput.value.trim().toLowerCase();
        searchResults.innerHTML = '';
        listInfo.innerHTML = '';
        listInfo.style.display = "none";
        if (searchTerm.length === 0) {
            searchResults.innerHTML = '';
            listInfo.innerHTML = '';
            listInfo.style.display = "none";
            return;
        }

        clearTimeout(timeoutId);

        timeoutId = setTimeout(() => {

            loadingMessage.style.display = 'block';

            fetch(json_communes_path, {
            headers: {
                'Content-Type': 'application/json; charset=utf-8'
            }
        }) // Remplacez 'votre_fichier.json' par le chemin vers votre fichier JSON
                .then(response => {
                if (!response.ok) {
                    throw new Error('Erreur lors du chargement du fichier');
                }
                return response.json();
                })
                .then(data => {
                    const decodedTerm = decodeURIComponent(searchTerm).toLowerCase();
                    const normalizedTerm = normalizeString(decodedTerm);
                const foundItems = data.filter(item => {
            const normalizedNomCommune = item.nom_commune_complet ? normalizeString(item.nom_commune_complet) : '';
            const normalizedCodePostal = item.code_postal ? normalizeString(item.code_postal) : '';
            const normalizedLigne5 = item.ligne_5 ? normalizeString(item.ligne_5) : '';

            return (
                normalizedNomCommune.includes(normalizedTerm) ||
                normalizedCodePostal == normalizedTerm ||
                "0"+normalizedCodePostal == normalizedTerm ||
                normalizedLigne5.includes(normalizedTerm)
            );
        });

                displayResults(foundItems.slice(0, 150)); // Afficher seulement les 5 premiers résultats
                loadingMessage.style.display = 'none';
                })
                .catch(error => {
                console.error("Une erreur s'est produite :', error");
                loadingMessage.style.display = 'none';
                });
            }, 1000);
        });

    searchInput.addEventListener('keydown', function(event) {
        if (event.key === 'Enter') {
            event.preventDefault(); // Prevent form submission or default behavior
            const firstResult = searchResults.querySelector('div'); // Select the first result
            if (firstResult) {
                firstResult.click(); // Simulate a click on the first result
            }
        }
    });
});

function escapeRegExp(string) {
    return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function normalizeString(text) {
    return text.normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/-/g, " ").toLowerCase();
}

function displayResults(results) {
if (!searchResults || !listInfo) {
    return;
}
searchResults.innerHTML = '';
listInfo.innerHTML = '';
//map.removeLayer(circle);
//circle = null;

if (results.length > 0) {
    results.forEach(item => {
    const listItem = document.createElement('div');
    listItem.setAttribute('onclick', 'searchMag('+item.code_postal+','+item.latitude+','+item.longitude+',"'+item.code_departement+'","'+item.nom_commune_complet+'")');
    if (item.ligne_5 != ""){
        listItem.textContent = item.nom_commune+" ("+item.ligne_5+") - "+item.code_postal; // Affiche la propriété 'name' dans la liste
    }else{
        listItem.textContent = item.nom_commune+" - "+item.code_postal; // Affiche la propriété 'name' dans la liste
    }
    //listItem.addEventListener("click", searchMag(item.Code_postal), false);
    searchResults.appendChild(listItem);
    });
} else {
    const noResultItem = document.createElement('div');
    noResultItem.textContent = 'Aucun résultat trouvé.';
    searchResults.appendChild(noResultItem);
}
}

//


export function searchMag(cp_prov,c_lat,c_lon,cdep,nom_commune_complet){
    if (!listInfo || !map) {
        return;
    }
    listInfo.innerHTML = '';

    if(useCricle){
        if(circle != null){
            map.removeLayer(circle);
            circle = null;
        }
    }

    loadStoresData()
    .then(data => {
        const foundItems = data
        .filter(mag => {
            // Rechercher dans plusieurs propriétés (name, description, category)
            return (mag.postcode && mag.postcode.toLowerCase() == cp_prov && mag.active == "1");
        });

        // Obtenir les index d'origine pour les éléments trouvés
        const indexes = foundItems.map((foundItem) => {
            return data.findIndex((item) => item === foundItem);
        });

        //console.log("found mag : "+foundItems[0]["city"])
        //console.log("index mag : "+foundItems[0]["city"])
        //console.log(foundItems[0]);
        var maker_id = indexes[0];
        //console.log(maker_id);

        if(foundItems.length != 0 || indexes.length != 0){

            var howManyResultNeeded = howManyResults+foundItems.length; //permet d'avoir toujours 4 mag car foundItems est sur le CP donc (nb de mag trouvé par CP + longeur de result voulu) puis on retire les double et sa nous donne 4

            marker_localize(maker_id);

            var result = findNearbyStoresRadiuses(c_lat, c_lon, searchradiuslist, howManyResultNeeded)
            .then(result => {

                const mergedFoundItems = [...foundItems, ...result.stores];
                const mergedIndexes = [...indexes, ...result.indexes];

                const uniqueFoundItems = mergedFoundItems.filter((arr, index, self) =>
                    index === self.findIndex((t) => JSON.stringify(t) === JSON.stringify(arr))
                );
                const uniqueIndexes = [...new Set(mergedIndexes)];

                generate_mag_list(cp_prov,"",uniqueIndexes,uniqueFoundItems);
                listInfo.style.display = "flex";

            })

        }else{
            console.log("pas de magasin sur cette commune");

            var result = findNearbyStoresRadiuses(c_lat, c_lon, searchradiuslist, howManyResults)
            .then(result => {

                const nearbyStores = result.stores
                const indexes = result.indexes
                const rad = result.radius
                const zoom = result.zoom

                //console.log(nearbyStores)
                //console.log(indexes)

                if(nearbyStores.length > 0 || indexes.length > 0){
                    //console.log("res1");
                    //console.log(indexes);
                    //listInfo.innerHTML += "<div class='listinfo-title' id='listinfo-title'>Voici les 4 magasins les plus proches de "+nom_commune_complet+" :</div><div class='listinfo-subtitle'>Rayon : "+rad+" km</div>";
                    generate_mag_list(cp_prov,"",indexes,nearbyStores);
                    listInfo.style.display = "flex";
                }
                map.setView([c_lat, c_lon], zoom);
                if(useCricle){
                    circle = L.circle([c_lat, c_lon], {
                            color: 'blue',
                            fillColor: '#4287f5',
                            fillOpacity: 0.01,
                            radius: (rad*1000) // 5 km en mètres
                        }).addTo(map);
                }

            })
        }
        })
        .catch(error => {
        console.error("Une erreur s'est produite :", error);
        });
        
}

if (typeof window !== 'undefined') {
    window.searchMag = searchMag;
}

/// --------------- NEARBY -------------------------------

function calculateDistance(lat1, lon1, lat2, lon2) {
const R = 6371; // Rayon de la Terre en kilomètres
const dLat = (lat2 - lat1) * (Math.PI / 180);
const dLon = (lon2 - lon1) * (Math.PI / 180);
const a =
    Math.sin(dLat / 2) * Math.sin(dLat / 2) +
    Math.cos(lat1 * (Math.PI / 180)) * Math.cos(lat2 * (Math.PI / 180)) *
    Math.sin(dLon / 2) * Math.sin(dLon / 2);
const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
const distance = R * c;
return distance;
}

// Fonction pour filtrer les magasins à moins de 5 km d'une position donnée
function findNearbyStores(latitude, longitude) {
    return new Promise((resolve, reject) => {
    const proximityThreshold = searchradius; // Distance en kilomètres
    const nearbyStores = [];

    loadStoresData()
    .then(tstore => {
        const foundStores = tstore
        foundStores.forEach(store => {
            const dist = calculateDistance(latitude, longitude, store.latitude, store.longitude);
            if (dist <= proximityThreshold) {
                nearbyStores.push(store);
            }
        })

    const indexes = nearbyStores.map((foundStore) => {
        return tstore.findIndex((item) => item === foundStore);
    })

        resolve([nearbyStores,indexes]);

    })


    })
}

function findNearbyStoresRadiuses(latitude, longitude, searchradiuslist, result_needed) {
return new Promise((resolve, reject) => {
    let index = 0;

    function fetchStoresWithRadius(radius) {
        const proximityThreshold = radius;

        loadStoresData()
            .then(tstore => {
                if(tstore.length < result_needed){
                    result_needed = tstore.length
                }
                const foundStores = tstore.filter(store => {
                    const dist = calculateDistance(latitude, longitude, store.latitude, store.longitude);
                    return dist <= proximityThreshold && store.active == "1";
                });

                if (foundStores.length >= result_needed) {
                    foundStores.sort((storeA, storeB) => {
                        const distA = calculateDistance(latitude, longitude, storeA.latitude, storeA.longitude);
                        const distB = calculateDistance(latitude, longitude, storeB.latitude, storeB.longitude);
                        return distA - distB;
                    });

                    resolve({
                        stores: foundStores.slice(0, result_needed),
                        radius: radius,
                        indexes: foundStores.slice(0, result_needed).map(store => tstore.findIndex(item => item === store)),
                        zoom: searchradiuslistzoommap[index]
                    });
                } else if (++index < searchradiuslist.length) {
                    fetchStoresWithRadius(searchradiuslist[index]);
                } else {
                    resolve({ stores: [], radius: null, indexes: null, zoom: null });
                }
            })
            .catch(error => {
                reject(error);
            });
    }

    fetchStoresWithRadius(searchradiuslist[index]);
});
}


/// --------------- MATH -------------------------------


function generate_mag_list(c_com, c_dep, marker_ids, data_found) {
    if (!searchResults || !listInfo) {
        return;
    }
    searchResults.innerHTML = '';

    for (let k = 0; k < data_found.length; k++) {
        if (k < 4) {
            listInfo.style.display = "flex";

            // Initialize adress2text with var instead of let for proper scoping
            var adress2text = "";

            if (data_found[k].address2) {
                adress2text = "<div class='sl-card-subtitle' id='card-subtitle-" + k + "-b'>" + data_found[k].address2 + "</div>";
            }

            // Initialize signature variable
            var signature = "";
            if ('icone' in data_found[k] && data_found[k].icone === 'signature') {
                signature = "<b style='color: #bd7639'>soins en institut</b><br>";
            }

            listInfo.innerHTML += "<div class='sl-card' id='card-" + k + "' onclick='marker_localize(" + marker_ids[k] + ")'><div class='sl-card-title' id='card-title-" + k + "'>" + data_found[k].name + "</div><div class='sl-card-subtitle' id='card-subtitle-" + k + "'>" + data_found[k].address1 + ", " + data_found[k].postcode + " " + data_found[k].city + "</div>" + adress2text + signature + "<a class='sl-btn' style='margin-top:15px; color:" + btncolor + "; background-color:" + btncolorbg + ";' onclick='event.stopPropagation(); ouvrirTrajetGoogleMapsCoordonnees(" + data_found[k].latitude + ", " + data_found[k].longitude + ")'>J'Y VAIS</a></div>";
        }
    }
}

function is_s(number){
    if(number > 1){
        return "s";
    }else{
        return "";
    }
}
