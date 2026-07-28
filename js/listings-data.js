// ---------- Shared listings data ----------
// Add a new object to this array to publish a new property — it will
// automatically appear in the listing grids and get its own detail page
// at immobilie.html?id=<id>.
window.LISTINGS = [
  {
    id: "altbau-donaustadt",
    title: "Altbau-Juwel mit Balkon",
    type: "kauf",
    price: "€ 495.000",
    location: "1220 Wien, Donaustadt",
    mapQuery: "Donaustadt, 1220 Wien",
    area: "92 m²",
    rooms: "3",
    baths: "2",
    gradient: "linear-gradient(135deg,#2c2822,#0f0e0c)",
    description: [
      "Dieser lichtdurchflutete Altbau überzeugt mit hohen Decken, Stuckdetails und einem sonnigen Balkon zum Innenhof. Die Raumaufteilung ist klassisch geschnitten und bietet viel Potenzial für individuelle Gestaltung.",
      "Die Lage in Donaustadt punktet mit guter Anbindung an die Öffentlichen sowie kurzen Wegen zu Nahversorgern, Schulen und der Alten Donau — ideal für alle, die Altbaucharme mit urbanem Wohnen verbinden möchten."
    ],
    video: null
  },
  {
    id: "einfamilienhaus-wolkersdorf",
    title: "Einfamilienhaus mit Garten",
    type: "kauf",
    price: "€ 389.000",
    location: "2120 Wolkersdorf",
    mapQuery: "Wolkersdorf, Niederösterreich",
    area: "140 m²",
    rooms: "5",
    baths: "2",
    gradient: "linear-gradient(135deg,#26241f,#121110)",
    description: [
      "Familienfreundliches Einfamilienhaus mit großzügigem Garten in ruhiger Siedlungslage. Die Wohnräume im Erdgeschoß gehen fließend in den Außenbereich über und sorgen für viel Platz zum Leben und Feiern.",
      "Fünf Zimmer verteilen sich auf zwei Geschoße und bieten Raum für die ganze Familie. Wolkersdorf punktet mit guter Infrastruktur und schneller Anbindung Richtung Wien."
    ],
    video: null
  },
  {
    id: "wallrissstrasse-wien",
    title: "Wohnung Wallrissstraße",
    type: "kauf",
    price: "€ 697.000",
    location: "Wallrissstraße, Wien",
    mapQuery: "Währing, 1180 Wien",
    area: "95 m²",
    rooms: "3",
    baths: "2",
    gradient: "linear-gradient(135deg,#2f2a22,#100f0d)",
    description: [
      "Diese gepflegte Wohnung in der Wallrissstraße überzeugt mit einem durchdachten Grundriss und hochwertiger Ausstattung. Die moderne Einbauküche sowie das großzügige Badezimmer mit freistehender Wanne wurden mit viel Liebe zum Detail gestaltet.",
      "Die hellen Wohnräume bieten viel Platz für den Alltag, ergänzt durch stilvolle Details wie den antiken Kleiderschrank im Wohnbereich. Ideal für alle, die Wert auf Qualität und eine ruhige, dennoch gut angebundene Lage legen. Kontaktieren Sie uns gerne für eine unverbindliche Besichtigung."
    ],
    video: {
      src: "assets/video/immobilie-wallrissstrasse.mp4",
      poster: "assets/img/immobilie-wallriss-poster.jpg",
      orientation: "portrait"
    }
  },
  {
    id: "villa-wolkersdorf",
    title: "Freistehende Villa",
    type: "kauf",
    price: "€ 615.000",
    location: "2120 Wolkersdorf",
    mapQuery: "Wolkersdorf, Niederösterreich",
    area: "210 m²",
    rooms: "6",
    baths: "3",
    gradient: "linear-gradient(135deg,#292521,#0f0e0c)",
    description: [
      "Repräsentative freistehende Villa mit großzügigem Grundstück und hochwertiger Ausstattung. Der offen gestaltete Wohn-Ess-Bereich sowie große Fensterflächen sorgen für ein helles, modernes Wohngefühl.",
      "Sechs Zimmer und drei Bäder bieten ausreichend Platz für große Familien oder repräsentative Zwecke. Die ruhige Lage in Wolkersdorf mit guter Anbindung an Wien rundet das Angebot ab."
    ],
    video: null
  },
  {
    id: "zwei-zimmer-wolkersdorf",
    title: "Helle Zwei-Zimmer-Wohnung",
    type: "miete",
    price: "€ 890 / Monat",
    location: "2120 Wolkersdorf",
    mapQuery: "Wolkersdorf, Niederösterreich",
    area: "54 m²",
    rooms: "2",
    baths: "1",
    gradient: "linear-gradient(135deg,#25211c,#121110)",
    description: [
      "Diese helle Zwei-Zimmer-Wohnung eignet sich ideal für Singles oder Paare, die Wert auf eine moderne, unkomplizierte Wohnsituation legen. Die Raumaufteilung ist effizient und funktional gestaltet.",
      "Die zentrale Lage in Wolkersdorf bietet kurze Wege zu Geschäften, Gastronomie und öffentlichen Verkehrsmitteln. Die Wohnung ist sofort beziehbar."
    ],
    video: null
  },
  {
    id: "maisonette-donaustadt",
    title: "Dachgeschoss-Maisonette",
    type: "kauf",
    price: "€ 329.000",
    location: "1220 Wien, Donaustadt",
    mapQuery: "Donaustadt, 1220 Wien",
    area: "78 m²",
    rooms: "3",
    baths: "2",
    gradient: "linear-gradient(135deg,#2d2823,#100f0d)",
    description: [
      "Diese Maisonette-Wohnung im Dachgeschoß besticht durch ihre besondere Raumhöhe und den offenen Wohnbereich über zwei Ebenen — ein Highlight für alle, die auf der Suche nach etwas Außergewöhnlichem sind.",
      "Drei Zimmer und zwei Bäder verteilen sich großzügig auf beide Ebenen. Die Lage in Donaustadt bietet eine ausgezeichnete Infrastruktur und Nähe zur Alten Donau."
    ],
    video: null
  }
];
