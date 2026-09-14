/**
 * Swiper Slim Entry — tree-shakeable import of only the modules TVPG uses.
 *
 * Why: The full swiper-bundle.min.js is 154KB. This entry produces a ~80KB
 * IIFE with navigation, thumbnails, manipulation, and fade support.
 *
 * @package TVPG
 * @since   1.5.0
 */
import Swiper from 'swiper';
import { Navigation, Thumbs, FreeMode, Keyboard, Manipulation, EffectFade } from 'swiper/modules';

// CSS imports — processed by PostCSS and extracted to swiper-slim.min.css.
import 'swiper/css';
import 'swiper/css/navigation';
import 'swiper/css/thumbs';
import 'swiper/css/free-mode';
import 'swiper/css/effect-fade';

Swiper.use([Navigation, Thumbs, FreeMode, Keyboard, Manipulation, EffectFade]);

window.TVPGSwiper = Swiper;
