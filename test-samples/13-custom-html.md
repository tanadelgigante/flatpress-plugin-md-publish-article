---
subject: Articolo con HTML custom
author: admin
date: 2026-09-16
categories: Tecnologia
---
Questo articolo testa l'uso di tag HTML diretti all'interno del Markdown, per verificare che vengano preservati correttamente nel passaggio al BBCode.

## Elementi di testo
Possiamo usare tag come <span style="color: red; font-weight: bold;">testo rosso e grassetto</span> o <u>testo sottolineato</u>.

## Contenitori e stili
<div style="background-color: #f9f9f9; border: 1px solid #ccc; padding: 15px; border-radius: 5px;">
  Questo è un contenitore `div` con stile inline, molto utile per messaggi di avviso o evidenziazioni.
</div>

## Immagini via HTML
A volte è più comodo inserire un'immagine direttamente con un tag `<img>` invece della sintassi Markdown:

<img src="https://example.com/image.png" alt="Immagine HTML" width="200">

## Link con stile
<a href="https://google.com" style="font-style: italic;">Link con stile inline</a>
