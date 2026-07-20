import gulp from 'gulp';
import concat from 'gulp-concat';
import cssuglify from 'gulp-uglifycss';
import jsuglify from 'gulp-uglify';
import gulpless from 'gulp-less';
import gulpautoprefixer from 'gulp-autoprefixer';
import mergeStream from 'merge-stream';

function lessTask() {
	const admin = gulp.src('./assets/css/admin.less')
		.pipe(gulpless())
		.pipe(gulpautoprefixer())
		.pipe(gulp.dest('./assets/css'))
		.pipe(cssuglify())
		.pipe(concat('admin.min.css'))
		.pipe(gulp.dest('./assets/css'));

	const wizard = gulp.src('./assets/css/wizard.less')
		.pipe(gulpless())
		.pipe(gulpautoprefixer())
		.pipe(gulp.dest('./assets/css'))
		.pipe(cssuglify())
		.pipe(concat('wizard.min.css'))
		.pipe(gulp.dest('./assets/css'));

	// Hand-authored CSS (not LESS): minify to .min.css only, never rewrite the source.
	const withdrawal = gulp.src('./assets/css/withdrawal-form.css')
		.pipe(cssuglify())
		.pipe(concat('withdrawal-form.min.css'))
		.pipe(gulp.dest('./assets/css'));

	return mergeStream(admin, wizard, withdrawal);
}
export { lessTask as less };

function jsTask() {
	const admin = gulp.src('assets/js/admin.js')
		.pipe(concat('admin.min.js'))
		.pipe(jsuglify())
		.pipe(gulp.dest('./assets/js'));

	const withdrawal = gulp.src('assets/js/withdrawal-form.js')
		.pipe(concat('withdrawal-form.min.js'))
		.pipe(jsuglify())
		.pipe(gulp.dest('./assets/js'));

	return mergeStream(admin, withdrawal);
}
export { jsTask as js };

function defaultTask(cb) {
	// Watch the .css source by name (not *.css) so the generated .min.css never retriggers.
	gulp.watch(
		[ './assets/css/*.less', './assets/css/withdrawal-form.css' ],
		{ ignoreInitial: false },
		lessTask
	);
	gulp.watch(
		[ './assets/js/admin.js', './assets/js/withdrawal-form.js' ],
		{ ignoreInitial: false },
		jsTask
	);
	cb();
}
export default defaultTask;
