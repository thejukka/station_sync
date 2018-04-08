# do_sync.php
Leimauspäätteen tietokannan synkronointi. Tämä tapahtuu PHP:llä tietyn väliajoin ja on tarkoitettu paikalliseen verkkoon, jossa kukin leimausasema on kytköksissä verkon tietokantapalvelimeen. Palvelimella oleva vastaavainen skripti pitää kirjaa siitä, milloin kyseinen leimausasema on viimeksi ollut yhteydessä, mikäli yhteys katkeaa ja tässä tapauksessa yhteyden palatessa kaikki viimeisimmät leimat haetaan.

# promidview.c
Netistä aikoinaan löydettyjen ohjeiden perusteella rakennettu GTK+WebView -softa x86- ja ARM -asemille. Eli miltei vastaavanlainen, kuin Androidille. Koodia kääntäessä käännetään sisään (static) myös tarvittavat kirjastot, jotta ohjelman siirto asemasta toiseen olisi äärimmäisen helppoa poropeukaloillekin - varsinkin etäisesti. Tämä onnistuu yleensä paremmin x86 -alustoilla, sillä pienitehoisilla ARM -alustoilla resurssit helposti loppuvat kesken.
