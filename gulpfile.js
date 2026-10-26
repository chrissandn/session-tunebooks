const gulp = require('gulp');
const sass = require('gulp-sass')(require('sass'));
var browserSync = require('browser-sync').create();
const sourcemaps = require('gulp-sourcemaps');
function defaultTask(cb) {
    // place code for your default task here
    cb();
}

function buildStyles() {
    return gulp.src('./**/*.scss')
        .pipe(sourcemaps.init())
        .pipe(sass().on('error', sass.logError))
        .pipe(sourcemaps.write())
        .pipe(browserSync.stream())
        .pipe(gulp.dest('./'));
};

 function serve() {

    browserSync.init({
        proxy: "localhost:8888",
        open: false,
        ghostMode: false
    });

    gulp.watch("./**/*.scss", buildStyles);
    gulp.watch("*.php").on('change', browserSync.reload);
    gulp.watch("*.js").on('change', browserSync.reload);
};


exports.buildStyles = buildStyles;
exports.watch = function () {
    gulp.watch('./**/*.scss', ['sass']);
};

exports.default = defaultTask
exports.serve = serve