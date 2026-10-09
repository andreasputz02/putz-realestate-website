// ---------- Objekte, die von Hand gepflegt werden ----------
//
// Objekte werden in Justimmo angelegt und dort fuer den API-Export
// freigegeben. justimmo.php haengt sie an diese Liste an (siehe dort).
//
// Diese Liste ist derzeit leer — sie bleibt bestehen, damit die Seite
// auch dann funktioniert, wenn Justimmo einmal nicht antwortet, und
// damit Objekte ohne Justimmo-Eintrag hier ergaenzt werden koennen.
window.LISTINGS = [
  {
    // Verkauft — bleibt als Beleg auf der Website stehen. Das Kennzeichen
    // "verkauft" setzt den Vermerk auf dem Bild und den Preis auf "Verkauft".
    id: "wohnung-hugogasse-1110",
    verkauft: true,
    type: "kauf",
    objektart: "Wohnung",
    title: "Wohnung in der Hugogasse, Top 6",
    location: "Hugogasse 8/6, 1110 Wien",
    plz: "1110",
    mapQuery: "Hugogasse 8, 1110 Wien",
    lat: 48.17117,
    lng: 16.41685,
    price: "Verkauft",
    area: "",
    grundArea: "",
    rooms: "",
    baths: "",
    gradient: "linear-gradient(135deg,#2c2822,#0f0e0c)",
    images: ["assets/img/verkauft/wohnung-1110-hugogasse.webp"],
    description: [
      "Diese Wohnung in der Hugogasse haben wir erfolgreich verkauft.",
      "Du hast etwas Ähnliches in Simmering und denkst über einen Verkauf nach? Melde dich — wir sagen dir ehrlich, was deine Wohnung derzeit wert ist."
    ]
  },
  {
    // Zweite verkaufte Wohnung im selben Haus. Die Koordinate ist um wenige
    // Meter versetzt, sonst laegen beide Nadeln exakt uebereinander.
    id: "wohnung-hugogasse-1110-top-35",
    verkauft: true,
    type: "kauf",
    objektart: "Wohnung",
    title: "Wohnung in der Hugogasse, Top 35",
    location: "Hugogasse 8/35, 1110 Wien",
    plz: "1110",
    mapQuery: "Hugogasse 8, 1110 Wien",
    lat: 48.17129,
    lng: 16.41699,
    price: "Verkauft",
    area: "",
    grundArea: "",
    rooms: "",
    baths: "",
    gradient: "linear-gradient(135deg,#2c2822,#0f0e0c)",
    images: ["assets/img/verkauft/wohnung-1110-hugogasse.webp"],
    description: [
      "Auch diese Wohnung in der Hugogasse haben wir erfolgreich verkauft.",
      "Du hast etwas Ähnliches in Simmering und denkst über einen Verkauf nach? Melde dich — wir sagen dir ehrlich, was deine Wohnung derzeit wert ist."
    ]
  }
];
