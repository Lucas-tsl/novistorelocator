/**
 * Test navigateur du store locator (Playwright).
 *
 *   npm i playwright
 *   node tests/e2e/storelocator.mjs "http://localhost:8080/?page_id=4" [dossier-captures]
 *
 * Les tuiles de carte sont remplacées par une image vide (aucun appel à MapTiler).
 * Variable d'environnement CHROMIUM_PATH pour utiliser un Chromium déjà installé.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const url = process.argv[2];
const out = process.argv[3] || 'e2e-captures';
if (!url) {
	console.error('Usage : node tests/e2e/storelocator.mjs <url de la page> [dossier-captures]');
	process.exit(1);
}
mkdirSync(out, { recursive: true });

const PNG_1PX = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
let failures = 0;
const check = (ok, label) => {
	console.log(`${ok ? '  ok  ' : '  FAIL'} ${label}`);
	if (!ok) failures++;
};

const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});

async function newPage(viewport, geo) {
	const context = await browser.newContext({
		viewport,
		locale: 'fr-FR',
		...(geo ? { geolocation: geo, permissions: ['geolocation'] } : {}),
	});
	const page = await context.newPage();
	const errors = [];
	const requests = {};
	page.on('pageerror', (e) => errors.push(e.message));
	page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
	page.on('request', (r) => {
		const name = r.url().split('?')[0].split('/').pop();
		requests[name] = (requests[name] || 0) + 1;
	});
	await page.route(/api\.maptiler\.com|tile\.openstreetmap\.org/, (route) => route.fulfill({ body: PNG_1PX, contentType: 'image/png' }));
	await page.goto(url, { waitUntil: 'networkidle' });
	return { page, errors, requests, context };
}

const root = '.novi-sl';
const input = `${root} .novi-sl__input`;

/* ---------------- Bureau ---------------- */
{
	const { page, errors, requests, context } = await newPage({ width: 1280, height: 900 });

	await page.waitForSelector(`${root} .novi-sl-cluster, ${root} .novi-sl-pin`);
	check(true, 'marqueurs regroupés affichés au chargement');
	check(!requests['communes.min.json'], 'fichier des communes non chargé avant la recherche');
	check(await page.locator('script[type="application/ld+json"]').count() === 1, 'données structurées JSON-LD présentes');
	const attribution = await page.locator(`${root} .leaflet-control-attribution`).innerText();
	check(/OpenStreetMap/.test(attribution), `crédit cartographique affiché (« ${attribution.replace(/\s+/g, ' ').trim()} »)`);
	check(await page.locator(`${root} style`).count() === 0, 'aucun <style> global injecté par le shortcode');

	// Recherche d'une ville, clavier uniquement.
	await page.fill(input, 'lyon');
	await page.waitForSelector(`${root} .novi-sl__suggestion--place`);
	const first = await page.locator(`${root} .novi-sl__suggestion`).first().innerText();
	check(/^Lyon/.test(first), `première suggestion pour « lyon » : ${first.replace(/\s+/g, ' ')}`);
	await page.keyboard.press('ArrowDown');
	check(await page.locator(input).getAttribute('aria-activedescendant') !== null, 'navigation clavier dans les suggestions (aria-activedescendant)');
	await page.keyboard.press('Enter');
	await page.waitForSelector(`${root} .novi-sl__card`);
	const cards = await page.locator(`${root} .novi-sl__card`).count();
	check(cards >= 4, `${cards} magasins listés après la recherche`);
	check(/km|m\b/.test(await page.locator(`${root} .novi-sl__distance`).first().innerText()), 'distance affichée sur chaque fiche');
	check(/plus proches de Lyon/.test(await page.locator(`${root} .novi-sl__status`).innerText()), 'message de statut annoncé');
	// « J'Y VAIS » : choix de l'application d'itinéraire.
	const go = page.locator(`${root} .novi-sl__card .novi-sl__go`).first();
	await go.locator('summary').click();
	const links = await go.locator('.novi-sl__go-link').evaluateAll((as) => as.map((a) => a.textContent + ' ' + a.href));
	check(links.length === 3 && /Google Maps https:\/\/www\.google\.com\/maps\/dir/.test(links[0]) && /Apple Plans https:\/\/maps\.apple\.com\/\?daddr=/.test(links[1]) && /Waze https:\/\/waze\.com\/ul\?ll=/.test(links[2]), '« J\'Y VAIS » propose Google Maps, Apple Plans et Waze');
	await page.keyboard.press('Escape');
	check(!(await go.evaluate((d) => d.open)), 'Échap referme le choix d\'application');

	// Molette sans Ctrl : la carte ne zoome pas (la page défile).
	const zoomBefore = await page.evaluate(() => document.querySelector('.novi-sl__map .leaflet-proxy').style.transform);
	await page.mouse.move(500, 600);
	await page.mouse.wheel(0, -400);
	await page.waitForTimeout(600);
	const zoomAfter = await page.evaluate(() => document.querySelector('.novi-sl__map .leaflet-proxy').style.transform);
	check(zoomBefore === zoomAfter, 'molette sans Ctrl : pas de zoom involontaire de la carte');
	await page.screenshot({ path: `${out}/1-recherche-lyon.png` });

	// Clic sur une fiche : popup ouvert et fiche mise en évidence.
	await page.locator(`${root} .novi-sl__card-title`).nth(1).click();
	await page.waitForSelector(`${root} .leaflet-popup .novi-sl-popup`, { timeout: 5000 });
	check(true, 'clic sur une fiche : popup du magasin ouvert');
	check(await page.locator(`${root} .novi-sl__card.is-active`).count() === 1, 'fiche active mise en évidence');
	await page.screenshot({ path: `${out}/2-fiche-ouverte.png` });

	// Escape ferme la liste ; code postal à 4 chiffres ; magasins étrangers ; noms de magasins.
	await page.fill(input, '');
	await page.fill(input, '1400');
	await page.locator(`${root} .novi-sl__suggestion--place`, { hasText: '01400' }).first().waitFor();
	check(/01400/.test(await page.locator(`${root} .novi-sl__suggestions`).innerText()), 'code postal saisi sans le zéro initial (1400 → 01400)');
	await page.keyboard.press('Escape');
	check(await page.locator(`${root} .novi-sl__suggestions`).isHidden(), 'Échap ferme les suggestions');

	await page.fill(input, 'luxembourg');
	await page.locator(`${root} .novi-sl__suggestion`, { hasText: 'Luxembourg' }).first().waitFor();
	check(/Luxembourg/.test(await page.locator(`${root} .novi-sl__suggestions`).innerText()), 'ville d\'un magasin hors de France trouvée');

	await page.fill(input, 'culey');
	await page.locator(`${root} .novi-sl__suggestion--place`, { hasText: 'Culey' }).first().click();
	await page.waitForFunction(() => /Culey/.test(document.querySelector('.novi-sl__status').textContent));
	check(true, 'commune autrefois sans coordonnées (Culey) utilisable');

	await page.fill(input, 'galeries lafayette nice');
	await page.locator(`${root} .novi-sl__suggestion--store`, { hasText: 'NICE' }).first().click();
	await page.waitForSelector(`${root} .leaflet-popup .novi-sl-popup`, { timeout: 5000 });
	await page.waitForTimeout(1500); // Fin des animations de zoom.
	const popups = await page.locator(`${root} .leaflet-popup`).allInnerTexts();
	check(popups.length === 1 && /NICE/i.test(popups[0]), `recherche par nom de magasin : un seul popup ouvert, sur le bon magasin (${popups.length})`);

	await page.fill(input, 'zzzzzz');
	await page.waitForSelector(`${root} .novi-sl__suggestion--message`);
	check(/Aucun résultat/.test(await page.locator(`${root} .novi-sl__suggestions`).innerText()), 'message « aucun résultat »');

	check((requests['communes.min.json'] || 0) === 1, `fichier des communes téléchargé une seule fois (${requests['communes.min.json'] || 0})`);
	check(errors.length === 0, `aucune erreur JavaScript${errors.length ? ' : ' + errors.join(' | ') : ''}`);
	await context.close();
}

/* ---------------- Filtres ---------------- */
{
	const { page, errors, context } = await newPage({ width: 1280, height: 900 });
	await page.waitForSelector(`${root} .novi-sl__chip`);
	const chips = await page.locator(`${root} .novi-sl__chip`).allInnerTexts();
	check(chips.length >= 3, `filtres affichés : ${chips.map((c) => c.replace(/\s+/g, ' ')).join(' | ')}`);
	const brandChip = page.locator(`${root} .novi-sl__chip--brand`).nth(1);
	const brand = (await brandChip.innerText()).replace(/\s*\d+$/, '').trim();
	await page.fill(input, 'paris');
	await page.locator(`${root} .novi-sl__suggestion--place`).first().click();
	await page.waitForSelector(`${root} .novi-sl__card`);
	await brandChip.click();
	await page.waitForTimeout(400);
	const names = await page.locator(`${root} .novi-sl__card-title`).allInnerTexts();
	check(names.length > 0 && names.every((n) => n.toUpperCase().startsWith(brand.toUpperCase())), `filtre « ${brand} » : seules ses fiches restent (${names.length})`);
	check(await brandChip.getAttribute('aria-pressed') === 'true', 'filtre actif signalé (aria-pressed)');
	await page.locator(`${root} .novi-sl__chip--all`).click();
	await page.waitForTimeout(300);
	check(await page.locator(`${root} .novi-sl__chip--all`).getAttribute('aria-pressed') === 'true', '« Tous » réinitialise les filtres');
	check(errors.length === 0, `aucune erreur JavaScript (filtres)${errors.length ? ' : ' + errors.join(' | ') : ''}`);
	await context.close();
}

/* ---------------- Géolocalisation + mobile ---------------- */
{
	const { page, errors, context } = await newPage({ width: 390, height: 844 }, { latitude: 48.8566, longitude: 2.3522 });
	// Permission déjà accordée : la position est utilisée sans clic.
	await page.waitForSelector(`${root} .novi-sl__card`, { timeout: 8000 });
	check(/votre position/.test(await page.locator(`${root} .novi-sl__status`).innerText()), 'géolocalisation déjà autorisée utilisée automatiquement');
	check(/origin=48\.8566/.test(await page.locator(`${root} .novi-sl__card .novi-sl__go-link--google`).first().getAttribute('href')), 'itinéraire depuis la position du visiteur');
	check(await page.locator(input).isVisible() && await page.locator(`${root} .novi-sl__results`).isVisible() && !(await page.locator(`${root} .novi-sl__map`).isVisible()), 'mobile : résultats affichés en vue Liste');
	await page.locator(`${root} .novi-sl__view[data-view="map"]`).click();
	check(await page.locator(`${root} .novi-sl__map`).isVisible() && !(await page.locator(`${root} .novi-sl__results`).isVisible()), 'mobile : bascule vers la vue Carte');
	await page.locator(`${root} .novi-sl__view[data-view="list"]`).click();
	await page.locator(`${root} .novi-sl__card-title`).nth(1).click();
	await page.waitForSelector(`${root} .leaflet-popup .novi-sl-popup`, { timeout: 5000 });
	check(await page.locator(`${root} .novi-sl__map`).isVisible(), 'mobile : un clic sur une fiche ouvre la carte sur le magasin');
	check(/Liste \(\d+\)/.test(await page.locator(`${root} .novi-sl__view[data-view="list"]`).innerText()), 'mobile : nombre de résultats sur le bouton Liste');
	await page.evaluate(() => window.scrollTo(0, 1200));
	const searchTop = await page.locator(`${root} .novi-sl__search`).evaluate((e) => e.getBoundingClientRect().top);
	check(searchTop >= -1 && searchTop < 5, `mobile : barre de recherche toujours visible en haut (${Math.round(searchTop)} px)`);
	const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
	check(!overflow, 'pas de défilement horizontal sur mobile');
	await page.screenshot({ path: `${out}/3-mobile-geoloc.png`, fullPage: true });
	check(errors.length === 0, `aucune erreur JavaScript (mobile)${errors.length ? ' : ' + errors.join(' | ') : ''}`);
	await context.close();
}

{
	const { page, context } = await newPage({ width: 1280, height: 900 });
	check(await page.locator(`${root} .novi-sl__card`).count() === 0, 'sans autorisation : aucune demande de position au chargement');
	await context.close();
}

await browser.close();
console.log(failures ? `\n${failures} échec(s)` : '\nTous les tests navigateur sont passés.');
process.exit(failures ? 1 : 0);
