<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * German strings for the fork-only "department-aware allocation" feature.
 *
 * Only the strings added by this fork are translated here. Everything else keeps coming from the
 * official German language pack, which is loaded after this file and therefore wins for any string
 * it defines.
 *
 * @package    mod_ratingallocate
 * @copyright  2026 Yurii Lysak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$string['configdiversityfield_default'] =
        'Voreinstellung für die Option „Gruppen durchmischen nach“ in neuen Aktivitäten „Gerechte Verteilung“.';
$string['diversitycapacitywarning'] =
        'Bei der Durchmischung nach einem Profilfeld werden alle Teilnehmer/innen verteilt; dafür müssen genügend Plätze vorhanden sein. Zurzeit ergeben die Maximalgrößen aller aktiven Wahlmöglichkeiten zusammen {$a->capacity} Plätze für {$a->users} Teilnehmer/innen – {$a->missing} zu wenig. Solange das so bleibt, bricht der Verteilungsalgorithmus mit einer Fehlermeldung ab. Erhöhen Sie die Maximalgrößen einzelner Wahlmöglichkeiten oder legen Sie eine weitere an.';
$string['diversityequalsize'] = 'Gruppengrößen';
$string['diversityequalsize_help'] = 'Ist die Option aktiviert (Standard), werden alle Gruppen gleich groß gefüllt: Die berücksichtigten Teilnehmer/innen werden gleichmäßig auf alle Wahlmöglichkeiten aufgeteilt. Eine Wahlmöglichkeit, die nur wenige gewählt haben, wird dann mit Teilnehmer/innen aufgefüllt, die sie nicht gewählt haben.

Deaktivieren Sie die Option, wenn alle, die abgestimmt haben, eine ihrer gewählten Wahlmöglichkeiten erhalten müssen. Es gibt dann keine Mindestgröße mehr, und jede Wahlmöglichkeit nimmt höchstens so viele Personen auf, wie ihre Maximalgröße zulässt. Wer abgestimmt hat, wird ausschließlich einer selbst gewählten Wahlmöglichkeit zugeordnet. Die Durchmischung nach dem Profilfeld wird weiterhin angestrebt, aber nur innerhalb dieser Wahlmöglichkeiten.

Beachten Sie die Folgen:

* Die Gruppen werden ungleich groß. Beliebte Wahlmöglichkeiten füllen sich bis zur Maximalgröße, unbeliebte bleiben klein oder leer.
* Die Gruppen sind weniger durchmischt, weil niemand mehr dorthin verschoben werden kann, wo der eigene Studiengang fehlt.
* Sind alle Wahlmöglichkeiten voll, die jemand gewählt hat, bleibt diese Person unverteilt, statt anderswo zugeordnet zu werden. Ordnen Sie sie anschließend über die Schaltfläche zur Verteilung der nicht zugeordneten Teilnehmer/innen (dabei kann jede Wahlmöglichkeit mit freien Plätzen verwendet werden) oder von Hand zu, oder erhöhen Sie die Maximalgrößen und starten Sie die Verteilung erneut.

Teilnehmer/innen ohne Abstimmung sind davon nicht betroffen: Sofern sie nicht über die Option oben ausgenommen sind, werden sie dort zugeordnet, wo Platz ist.';
$string['diversityequalsize_label'] = 'Alle Gruppen gleich groß füllen';
$string['diversityfield'] = 'Gruppen durchmischen nach';
$string['diversityfield_default'] = 'Voreinstellung für „Gruppen durchmischen nach“';
$string['diversityfield_help'] = 'Ist diese Option gesetzt, versucht die Verteilung, in jede Gruppe mindestens eine Person je Ausprägung des gewählten Profilfelds zu bringen – zum Beispiel je Studiengang, sofern dieser im Profilfeld „Abteilung“ hinterlegt ist. So werden die Gruppen möglichst breit durchmischt.

Damit ändert sich die Arbeitsweise der Verteilung grundlegend: Statt Wahlmöglichkeiten ohne Abstimmungen leer zu lassen und einzelne Teilnehmer/innen unverteilt zu lassen, erfolgt eine **vollständige, ausgeglichene Verteilung** – alle Teilnehmer/innen werden zugeordnet, und jede Wahlmöglichkeit wird auf eine gleichmäßige Gruppengröße aufgefüllt, wobei die jeweilige Maximalgröße eingehalten wird. Teilnehmer/innen ohne Abstimmung können Sie mit der Option „Teilnehmer/innen ohne Abstimmung“ weiter unten von der Verteilung ausnehmen. Auf die gleichmäßige Gruppengröße können Sie mit der Option „Gruppengrößen“ verzichten, damit niemand, der abgestimmt hat, einer nicht gewählten Wahlmöglichkeit zugeordnet wird.

Die beiden Ziele werden nicht gegeneinander abgewogen, sondern nacheinander verfolgt: Zuerst deckt die Verteilung so viele Kombinationen aus Gruppe und Feldwert ab wie möglich, und erst unter diesen Lösungen sucht sie diejenige, die den Abstimmungen am besten entspricht. Es kann deshalb vorkommen, dass Teilnehmer/innen eine niedriger bewertete Wahlmöglichkeit erhalten – oder eine, über die sie gar nicht abgestimmt haben –, wenn nur so ihr Studiengang in einer Gruppe vertreten ist, in der er sonst fehlen würde. Mit der Option „Vorrang“ weiter unten lässt sich diese Reihenfolge umkehren.

Da für alle ein Platz vorhanden sein muss, müssen die Maximalgrößen aller aktiven Wahlmöglichkeiten zusammen mindestens der Anzahl der Teilnehmer/innen entsprechen. Andernfalls bricht die Verteilung mit einer Fehlermeldung ab, ohne jemanden zuzuordnen.

Gibt es zu einer Ausprägung weniger Teilnehmer/innen als Gruppen, lässt sich rechnerisch nicht jede Gruppe abdecken. Die Verteilung deckt dann so viele Gruppen wie möglich ab und weist anschließend aus, welche Kombinationen offen geblieben sind.';
$string['diversityfield_off'] = 'Aus (nicht durchmischen)';
$string['diversityinfeasible'] =
        'Die Verteilung mit Durchmischung konnte nicht alle Teilnehmer/innen zuordnen: Mit den derzeitigen Gruppenbeschränkungen der Wahlmöglichkeiten lässt sich nicht jede Wahlmöglichkeit auf ihre Mindestgröße auffüllen. Prüfen Sie bei den Wahlmöglichkeiten die Einstellung „Sichtbarkeit durch Gruppen eingeschränkt“ und starten Sie die Verteilung anschließend erneut.';
$string['diversityinfeasible_topup'] =
        'Für die noch nicht zugeordneten Teilnehmer/innen ist nicht genügend Platz: In den Wahlmöglichkeiten sind {$a->capacity} Plätze frei, zugeordnet werden müssen aber {$a->users} Teilnehmer/innen. Erhöhen Sie die Maximalgrößen der Wahlmöglichkeiten oder legen Sie eine weitere an und verteilen Sie die übrigen Teilnehmer/innen anschließend erneut.';
$string['diversityinfeasible_capacity'] =
        'Bei der Verteilung mit Durchmischung braucht jede Person einen Platz: Die Maximalgrößen aller aktiven Wahlmöglichkeiten ergeben zusammen nur {$a->capacity} Plätze für {$a->users} Teilnehmer/innen. Erhöhen Sie die Maximalgrößen der Wahlmöglichkeiten oder legen Sie eine weitere Wahlmöglichkeit an und starten Sie die Verteilung anschließend erneut.';
$string['diversityminsize'] = 'Mindestgröße der Gruppen';
$string['diversityminsize_help'] = 'Nur verfügbar, wenn „Alle Gruppen gleich groß füllen“ deaktiviert ist. Eine Wahlmöglichkeit, die weniger Teilnehmer/innen erhalten würde als hier angegeben, wird gestrichen: Sie bekommt niemanden, und die Verteilung wird im selben Durchlauf ohne sie neu berechnet. Die betroffenen Teilnehmer/innen werden einer anderen von ihnen gewählten Wahlmöglichkeit zugeordnet. Wer in keiner davon mehr Platz findet, bleibt unverteilt.

Niemand wird einer Wahlmöglichkeit nur deshalb zugeordnet, damit sie die Mindestgröße erreicht. Tragen Sie 0 ein, wenn es keine Mindestgröße geben soll.

Eine hohe Mindestgröße streicht viele Wahlmöglichkeiten und kann dazu führen, dass Teilnehmer/innen unverteilt bleiben. Die Verteilung der nicht zugeordneten Teilnehmer/innen prüft die Mindestgröße nicht und kann gestrichene Wahlmöglichkeiten wieder verwenden.';
$string['diversityminsizenotice'] = 'Diese Wahlmöglichkeiten haben weniger als {$a} Teilnehmer/innen (Mindestgröße der Gruppen) und wurden bei der Verteilung deshalb leer gelassen:';
$string['diversitynocoverage'] =
        'Durchmischung nach einem Profilfeld: {$a} Kombination(en) aus Gruppe und Feldwert konnten nicht abgedeckt werden.';
$string['diversitypriority'] = 'Vorrang';
$string['diversitypriority_help'] = 'Legt fest, welches Ziel Vorrang hat, wenn sich die Durchmischung der Gruppen und die Abstimmungen der Teilnehmer/innen nicht gleichzeitig erfüllen lassen.

* **Zuerst Durchmischung**: Die Verteilung deckt so viele Kombinationen aus Gruppe und Feldwert ab wie möglich und sucht erst unter diesen Lösungen diejenige, die den Abstimmungen am besten entspricht. Teilnehmer/innen erhalten häufiger eine niedriger bewertete Wahlmöglichkeit.
* **Zuerst Abstimmungen**: Die Verteilung gibt den Teilnehmer/innen die bestmöglichen Wahlmöglichkeiten und durchmischt die Gruppen erst unter diesen Lösungen so gut wie möglich. Die Gruppen sind weniger durchmischt.

Die übrigen Regeln bleiben unberührt: Gruppengrößen und berücksichtigte Teilnehmer/innen richten sich nach den Optionen oben.';
$string['diversitypriority_mix'] = 'Zuerst Durchmischung der Gruppen, dann Abstimmungen';
$string['diversitypriority_ratings'] = 'Zuerst Abstimmungen, dann Durchmischung der Gruppen';
$string['diversityreport_blocked'] =
        'Für die Wahlmöglichkeit „{$a->choice}“ war wegen der Gruppenbeschränkungen niemand mit der Ausprägung „{$a->value}“ zugelassen.';
$string['diversityreport_heading'] = 'Durchmischung der Gruppen';
$string['diversityreport_impossible'] =
        'Zur Ausprägung „{$a->value}“ gibt es nur {$a->count} Teilnehmer/innen, aber {$a->groups} Gruppen. Sie kann daher in höchstens {$a->count} Gruppe(n) vertreten sein.';
$string['diversityskipunrated'] = 'Teilnehmer/innen ohne Abstimmung';
$string['diversityskipunrated_help'] = 'Standardmäßig werden alle Teilnehmer/innen verteilt, auch diejenigen, die nie abgestimmt haben. Wenn Sie diese Option aktivieren, werden nur Teilnehmer/innen berücksichtigt, die mindestens eine Wahlmöglichkeit bewertet haben. Alle übrigen bleiben zunächst unverteilt und können danach über die Schaltflächen zur Verteilung der nicht zugeordneten Teilnehmer/innen oder von Hand zugeordnet werden.

Gedacht ist die Option für Kursregeln wie „Keine Abstimmung, kein automatischer Platz“ – nicht als Schutz für diejenigen, die abgestimmt haben. Für diese fällt das Ergebnis erfahrungsgemäß sogar schlechter aus: Teilnehmer/innen ohne Abstimmung sind für den Algorithmus zwischen allen Wahlmöglichkeiten austauschbar. Er nutzt sie, um die Gruppen zu füllen und auszugleichen, und behält dadurch den Spielraum, den Wünschen der übrigen zu entsprechen. Nimmt man sie aus der Berechnung heraus, müssen genau diejenigen die Gruppen füllen und ausgleichen, die abgestimmt haben.

Beachten Sie außerdem: Die Gruppengrößen werden immer über die tatsächlich berücksichtigten Teilnehmer/innen ausgeglichen. Sind es weniger, fallen die Gruppen entsprechend kleiner aus.';
$string['diversityskipunrated_label'] = 'Teilnehmer/innen ohne Abstimmung nicht verteilen';
