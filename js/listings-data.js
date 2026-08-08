// ---------- Objekte, die von Hand gepflegt werden ----------
//
// Neue Objekte werden NICHT mehr hier eingetragen, sondern in Justimmo
// angelegt und dort fuer den API-Export freigegeben. justimmo.php haengt
// sie an diese Liste an (siehe dort).
//
// Hier bleiben nur Objekte, die es in Justimmo nicht gibt — derzeit die
// Wallrissstrasse mit eigenen Fotos und Video-Rundgang.
window.LISTINGS = [
  {
    id: "wallrissstrasse-wien",
    title: "Wohnung Wallrissstraße",
    type: "kauf",
    price: "€ 697.000",
    location: "Wallrissstraße, Wien",
    mapQuery: "Währing, 1180 Wien",
    // Fuer den Umkreis auf der Detailseite. Bei Objekten aus Justimmo
    // kommen diese Werte automatisch mit.
    lat: 48.2330851,
    lng: 16.3208820,
    area: "95 m²",
    rooms: "3",
    baths: "2",
    // Zahlenwerte für den Filter — bei Justimmo-Objekten kommen sie
    // automatisch mit.
    preisWert: 697000,
    flaecheWert: 95,
    zimmerWert: 3,
    objektart: "Wohnung",
    plz: "1180",
    gradient: "linear-gradient(135deg,#2f2a22,#100f0d)",
    // Erstes Bild dient zugleich als Deckblatt-Hintergrund.
    images: [
      "assets/img/objekte/wallriss/wallriss-01.jpg",
      "assets/img/objekte/wallriss/wallriss-02.jpg",
      "assets/img/objekte/wallriss/wallriss-03.jpg",
      "assets/img/objekte/wallriss/wallriss-04.jpg",
      "assets/img/objekte/wallriss/wallriss-05.jpg",
      "assets/img/objekte/wallriss/wallriss-06.jpg",
      "assets/img/objekte/wallriss/wallriss-07.jpg",
      "assets/img/objekte/wallriss/wallriss-08.jpg",
      "assets/img/objekte/wallriss/wallriss-09.jpg",
      "assets/img/objekte/wallriss/wallriss-10.jpg",
      "assets/img/objekte/wallriss/wallriss-11.jpg",
      "assets/img/objekte/wallriss/wallriss-12.jpg",
      "assets/img/objekte/wallriss/wallriss-13.jpg"
    ],
    description: [
      "Diese gepflegte Wohnung in der Wallrissstraße überzeugt mit einem durchdachten Grundriss und hochwertiger Ausstattung. Die moderne Einbauküche sowie das großzügige Badezimmer mit freistehender Wanne wurden mit viel Liebe zum Detail gestaltet.",
      "Die hellen Wohnräume bieten viel Platz für den Alltag, ergänzt durch stilvolle Details wie den antiken Kleiderschrank im Wohnbereich. Ideal für alle, die Wert auf Qualität und eine ruhige, dennoch gut angebundene Lage legen. Melde dich gerne für eine unverbindliche Besichtigung."
    ],
    video: {
      src: "assets/video/immobilie-wallrissstrasse.mp4",
      poster: "assets/img/immobilie-wallriss-poster.jpg",
      orientation: "portrait"
    }
  }
];
